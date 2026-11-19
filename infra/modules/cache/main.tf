# Redis-compatible cache, sessions and queues (Valkey on ElastiCache — Sprint 17.1). Encrypted in transit and at rest,
# with an auth token; a replica in another zone with automatic failover in production.
# The app connects with REDIS_HOST=tls://<primary endpoint> (phpredis), REDIS_PASSWORD from `wurk/<env>/app`.

terraform {
  required_providers {
    aws    = { source = "hashicorp/aws", version = "~> 5.80" }
    random = { source = "hashicorp/random", version = "~> 3.6" }
  }
}

resource "aws_elasticache_subnet_group" "this" {
  name       = var.name
  subnet_ids = var.subnet_ids
}

resource "aws_security_group" "cache" {
  name        = "${var.name}-cache"
  description = "Valkey: only from WURK's containers"
  vpc_id      = var.vpc_id
  tags        = { Name = "${var.name}-cache" }
}

resource "aws_vpc_security_group_ingress_rule" "cache_from_app" {
  security_group_id            = aws_security_group.cache.id
  description                  = "Valkey from WURK's containers"
  referenced_security_group_id = var.app_security_group_id
  ip_protocol                  = "tcp"
  from_port                    = 6379
  to_port                      = 6379
}

resource "random_password" "auth" {
  length  = 64
  special = false
}

resource "aws_elasticache_replication_group" "this" {
  replication_group_id = var.name
  description          = "WURK cache, sessions and queues"
  engine               = "valkey"
  engine_version       = var.engine_version
  node_type            = var.node_type
  port                 = 6379
  parameter_group_name = "default.valkey8"

  num_cache_clusters         = var.replicas + 1
  automatic_failover_enabled = var.replicas > 0
  multi_az_enabled           = var.replicas > 0

  subnet_group_name  = aws_elasticache_subnet_group.this.name
  security_group_ids = [aws_security_group.cache.id]

  at_rest_encryption_enabled = true
  kms_key_id                 = var.kms_key_arn
  transit_encryption_enabled = true
  auth_token                 = random_password.auth.result

  snapshot_retention_limit   = var.snapshot_retention_days
  snapshot_window            = "01:30-02:30"
  maintenance_window         = "sun:02:45-sun:03:45"
  auto_minor_version_upgrade = true
  apply_immediately          = false

  lifecycle {
    ignore_changes = [auth_token]
  }
}
