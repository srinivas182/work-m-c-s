# MySQL 8 for WURK (Sprint 17.1, decision F): the central database and one instance per shard.
# - Multi-AZ in production; encrypted with the environment's key; TLS required.
# - Point-in-time restore for `backup_retention_days` (35 in production), snapshots copied to tags.
# - Each database's credentials go to Secrets Manager (`wurk/<env>/db`); the app reads them at start-up and shards are
#   registered with them (`shards:register`, a release task in 17.2). No automatic rotation: a shard's credentials are
#   stored in the central database, so rotation is a planned step (`shards:repoint`), documented in the runbook.

terraform {
  required_providers {
    aws    = { source = "hashicorp/aws", version = "~> 5.80" }
    random = { source = "hashicorp/random", version = "~> 3.6" }
  }
}

resource "aws_db_subnet_group" "this" {
  name       = var.name
  subnet_ids = var.subnet_ids
}

resource "aws_security_group" "db" {
  name        = "${var.name}-mysql"
  description = "MySQL: only from WURK's containers"
  vpc_id      = var.vpc_id
  tags        = { Name = "${var.name}-mysql" }
}

resource "aws_vpc_security_group_ingress_rule" "db_from_app" {
  security_group_id            = aws_security_group.db.id
  description                  = "MySQL from WURK's containers"
  referenced_security_group_id = var.app_security_group_id
  ip_protocol                  = "tcp"
  from_port                    = 3306
  to_port                      = 3306
}

resource "aws_db_parameter_group" "this" {
  name   = "${var.name}-mysql80"
  family = "mysql8.0"

  parameter {
    name  = "require_secure_transport"
    value = "1"
  }
  parameter {
    name  = "character_set_server"
    value = "utf8mb4"
  }
  parameter {
    name  = "collation_server"
    value = "utf8mb4_unicode_ci"
  }
  parameter {
    name  = "time_zone"
    value = "UTC"
  }
  parameter {
    name  = "slow_query_log"
    value = "1"
  }
  parameter {
    name  = "long_query_time"
    value = "1"
  }
}

resource "random_password" "db" {
  for_each = var.databases
  length   = 40
  special  = false # safe in URLs, shell and the app's env
}

resource "aws_db_instance" "this" {
  for_each = var.databases

  identifier     = "${var.name}-${replace(each.key, "_", "-")}"
  engine         = "mysql"
  engine_version = var.engine_version
  instance_class = each.value.instance_class

  db_name  = "wurk"
  username = "wurk"
  password = random_password.db[each.key].result

  allocated_storage     = each.value.storage_gb
  max_allocated_storage = each.value.max_storage_gb
  storage_type          = "gp3"
  storage_encrypted     = true
  kms_key_id            = var.kms_key_arn

  multi_az               = var.multi_az
  db_subnet_group_name   = aws_db_subnet_group.this.name
  vpc_security_group_ids = [aws_security_group.db.id]
  publicly_accessible    = false
  parameter_group_name   = aws_db_parameter_group.this.name
  ca_cert_identifier     = "rds-ca-rsa2048-g1"

  backup_retention_period  = var.backup_retention_days
  backup_window            = "00:30-01:30" # 02:30–03:30 SAST, quiet hours
  maintenance_window       = "sun:01:45-sun:02:45"
  copy_tags_to_snapshot    = true
  delete_automated_backups = false

  deletion_protection       = var.deletion_protection
  skip_final_snapshot       = false
  final_snapshot_identifier = "${var.name}-${replace(each.key, "_", "-")}-final"

  auto_minor_version_upgrade            = true
  performance_insights_enabled          = true
  performance_insights_kms_key_id       = var.kms_key_arn
  performance_insights_retention_period = 7
  monitoring_interval                   = 60
  monitoring_role_arn                   = aws_iam_role.monitoring.arn
  enabled_cloudwatch_logs_exports       = ["error", "slowquery"]
  iam_database_authentication_enabled   = true

  tags = { Name = "${var.name}-${each.key}", Role = each.key == "central" ? "central" : "shard", Backup = "wurk" }

  lifecycle {
    ignore_changes = [password] # changed only through the documented rotation
  }
}

resource "aws_iam_role" "monitoring" {
  name = "${var.name}-rds-monitoring"
  assume_role_policy = jsonencode({
    Version   = "2012-10-17"
    Statement = [{ Effect = "Allow", Principal = { Service = "monitoring.rds.amazonaws.com" }, Action = "sts:AssumeRole" }]
  })
}

resource "aws_iam_role_policy_attachment" "monitoring" {
  role       = aws_iam_role.monitoring.name
  policy_arn = "arn:aws:iam::aws:policy/service-role/AmazonRDSEnhancedMonitoringRole"
}

resource "aws_secretsmanager_secret" "db" {
  name        = "wurk/${var.environment}/db"
  description = "WURK's MySQL databases: host, port, database, username and password for the central database and each shard"
  kms_key_id  = var.kms_key_arn
}

resource "aws_secretsmanager_secret_version" "db" {
  secret_id = aws_secretsmanager_secret.db.id
  secret_string = jsonencode({
    for k, db in aws_db_instance.this : k => {
      host = db.address, port = db.port, database = db.db_name, username = db.username, password = random_password.db[k].result
    }
  })
}
