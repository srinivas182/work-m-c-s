# WURK's containers on ECS Fargate (Sprint 17.1, decision B). One image, five roles:
#   serve      web (Octane on FrankenPHP, :8000) behind the load balancer, autoscaled on CPU and requests;
#   worker     queue workers, autoscaled on CPU;
#   scheduler  exactly one (deployments stop the old one before starting the new);
#   ssr        server-side rendering for wurk.co.za (:13714), private;
#   clamav     malware scanning for visitors' uploads (:3310), private.
# `migrate` and the other release tasks run as one-off tasks of the `release` task definition (17.2).
# Rolling deployments with the circuit breaker: a release whose containers don't become healthy rolls back by itself.

terraform {
  required_providers {
    aws = { source = "hashicorp/aws", version = "~> 5.80" }
  }
}

data "aws_region" "this" {}
data "aws_caller_identity" "this" {}

locals {
  image = "${aws_ecr_repository.wurk.repository_url}:${var.image_tag}"

  # Environment shared by every role. Secrets come from Secrets Manager, never from here.
  environment = concat([
    for k, v in merge({
      APP_ENV              = "production"
      APP_URL              = "https://${var.app_domain}"
      WURK_ROOT_DOMAIN     = var.root_domain
      WURK_APP_DOMAIN      = var.app_domain
      LOG_CHANNEL          = "stderr"
      LOG_STDERR_FORMATTER = "Monolog\\Formatter\\JsonFormatter"
      DB_CONNECTION        = "mysql"
      DB_HOST              = var.central_db_host
      DB_PORT              = "3306"
      DB_DATABASE          = "wurk"
      MYSQL_ATTR_SSL_CA    = "/etc/ssl/certs/rds-global-bundle.pem"
      REDIS_CLIENT         = "phpredis"
      REDIS_HOST           = "tls://${var.redis_host}"
      REDIS_PORT           = "6379"
      CACHE_STORE          = "redis"
      SESSION_DRIVER       = "redis"
      QUEUE_CONNECTION     = "redis"
      FILES_DRIVER         = "s3"
      FILES_BUCKET         = var.files_bucket
      FILES_PUBLIC_URL     = var.files_public_url
      AWS_DEFAULT_REGION   = data.aws_region.this.name
      CLAMAV_HOST          = "clamav.${var.name}.internal"
      CLAMAV_PORT          = "3310"
      TRUSTED_PROXIES      = var.vpc_cidr
      BACKUP_TARGETS       = join(",", var.backup_targets)
      INERTIA_SSR_URL      = "http://ssr.${var.name}.internal:13714"
      WURK_RELEASE         = var.image_tag # replaced by each release (17.2)
    }, var.extra_environment) : { name = k, value = tostring(v) }
  ])

  secrets = [
    { name = "APP_KEY", valueFrom = "${aws_secretsmanager_secret.app.arn}:APP_KEY::" },
    { name = "REDIS_PASSWORD", valueFrom = "${aws_secretsmanager_secret.app.arn}:REDIS_PASSWORD::" },
    { name = "WATCHTOWER_ALARM_TOKEN", valueFrom = "${aws_secretsmanager_secret.app.arn}:WATCHTOWER_ALARM_TOKEN::" }, # 17.5
    { name = "CLOUDFLARE_API_TOKEN", valueFrom = "${aws_secretsmanager_secret.app.arn}:CLOUDFLARE_API_TOKEN::" },     # 17.3, custom hostnames only
    { name = "DB_USERNAME", valueFrom = "${var.db_secret_arn}:central.username::" },
    { name = "DB_PASSWORD", valueFrom = "${var.db_secret_arn}:central.password::" },
  ]

  roles = {
    serve     = { command = ["serve"], cpu = var.serve.cpu, memory = var.serve.memory, port = 8000 }
    worker    = { command = ["worker"], cpu = var.worker.cpu, memory = var.worker.memory, port = null }
    scheduler = { command = ["scheduler"], cpu = 256, memory = 512, port = null }
    ssr       = { command = ["ssr"], cpu = 256, memory = 512, port = 13714 }
    release   = { command = ["migrate"], cpu = 512, memory = 1024, port = null }
  }
}

# --- Image registry ---------------------------------------------------------------------------------------------------

resource "aws_ecr_repository" "wurk" {
  name                 = "${var.name}/wurk"
  image_tag_mutability = "IMMUTABLE" # a version's image never changes
  image_scanning_configuration {
    scan_on_push = true
  }
  encryption_configuration {
    encryption_type = "KMS"
    kms_key         = var.kms_key_arn
  }
}

# Production's deploy role copies the image staging tested (same digest) — staging's registry lets it pull.
resource "aws_ecr_repository_policy" "pull" {
  count      = length(var.ecr_pull_role_arns) > 0 ? 1 : 0
  repository = aws_ecr_repository.wurk.name
  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{
      Sid       = "ProductionPulls"
      Effect    = "Allow"
      Principal = { AWS = var.ecr_pull_role_arns }
      Action    = ["ecr:BatchGetImage", "ecr:GetDownloadUrlForLayer", "ecr:BatchCheckLayerAvailability"]
    }]
  })
}

resource "aws_ecr_lifecycle_policy" "wurk" {
  repository = aws_ecr_repository.wurk.name
  policy = jsonencode({
    rules = [{
      rulePriority = 1
      description  = "Keep the last 30 images (rollbacks)"
      selection    = { tagStatus = "any", countType = "imageCountMoreThan", countNumber = 30 }
      action       = { type = "expire" }
    }]
  })
}

# --- Cluster, logs, roles -----------------------------------------------------------------------------------------------

resource "aws_ecs_cluster" "this" {
  name = var.name
  setting {
    name  = "containerInsights"
    value = "enabled"
  }
}

resource "aws_service_discovery_private_dns_namespace" "this" {
  name = "${var.name}.internal"
  vpc  = var.vpc_id
}

resource "aws_cloudwatch_log_group" "role" {
  for_each          = merge(local.roles, { clamav = null })
  name              = "/wurk/${var.name}/${each.key}"
  retention_in_days = var.log_retention_days
  kms_key_id        = var.kms_key_arn
}

resource "aws_secretsmanager_secret" "app" {
  name        = "wurk/${var.environment}/app"
  description = "WURK infrastructure secrets: APP_KEY (set once, by hand — never changed), REDIS_PASSWORD, CLOUDFLARE_API_TOKEN (custom hostnames)"
  kms_key_id  = var.kms_key_arn
}

# REDIS_PASSWORD is written here; APP_KEY is added by hand once (docs: infra/README.md) and kept when this runs again.
resource "aws_secretsmanager_secret_version" "app" {
  secret_id     = aws_secretsmanager_secret.app.id
  secret_string = jsonencode({ APP_KEY = "set-me", REDIS_PASSWORD = var.redis_auth_token, CLOUDFLARE_API_TOKEN = "", WATCHTOWER_ALARM_TOKEN = var.alarm_token })
  lifecycle {
    ignore_changes = [secret_string]
  }
}

data "aws_iam_policy_document" "ecs_assume" {
  statement {
    actions = ["sts:AssumeRole"]
    principals {
      type        = "Service"
      identifiers = ["ecs-tasks.amazonaws.com"]
    }
    condition {
      test     = "ArnLike"
      variable = "aws:SourceArn"
      values   = ["arn:aws:ecs:${data.aws_region.this.name}:${data.aws_caller_identity.this.account_id}:*"]
    }
  }
}

# Used by ECS to start containers: pull the image, write logs, read the secrets.
resource "aws_iam_role" "execution" {
  name               = "${var.name}-ecs-execution"
  assume_role_policy = data.aws_iam_policy_document.ecs_assume.json
}

resource "aws_iam_role_policy_attachment" "execution" {
  role       = aws_iam_role.execution.name
  policy_arn = "arn:aws:iam::aws:policy/service-role/AmazonECSTaskExecutionRolePolicy"
}

resource "aws_iam_role_policy" "execution_secrets" {
  role = aws_iam_role.execution.id
  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      { Effect = "Allow", Action = ["secretsmanager:GetSecretValue"], Resource = [aws_secretsmanager_secret.app.arn, var.db_secret_arn] },
      { Effect = "Allow", Action = ["kms:Decrypt"], Resource = [var.kms_key_arn] },
    ]
  })
}

# Used by WURK itself: its files bucket (and nothing else in S3), the database secret (to register shards), metrics.
resource "aws_iam_role" "task" {
  name               = "${var.name}-ecs-task"
  assume_role_policy = data.aws_iam_policy_document.ecs_assume.json
}

resource "aws_iam_role_policy" "task" {
  role = aws_iam_role.task.id
  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      { Effect = "Allow", Action = ["s3:ListBucket", "s3:ListBucketVersions"], Resource = [var.files_bucket_arn] },
      {
        Effect   = "Allow"
        Action   = ["s3:GetObject", "s3:GetObjectVersion", "s3:PutObject", "s3:PutObjectAcl", "s3:DeleteObject", "s3:AbortMultipartUpload"]
        Resource = ["${var.files_bucket_arn}/*"]
      },
      { Effect = "Allow", Action = ["kms:Decrypt", "kms:GenerateDataKey"], Resource = [var.kms_key_arn] },
      { Effect = "Allow", Action = ["secretsmanager:GetSecretValue"], Resource = [var.db_secret_arn] },
      { Effect = "Allow", Action = ["cloudwatch:PutMetricData"], Resource = ["*"], Condition = { StringEquals = { "cloudwatch:namespace" = "WURK" } } },
      { Effect = "Allow", Action = ["ssmmessages:CreateControlChannel", "ssmmessages:CreateDataChannel", "ssmmessages:OpenControlChannel", "ssmmessages:OpenDataChannel"], Resource = ["*"] },
    ]
  })
}

# --- Security groups ----------------------------------------------------------------------------------------------------

resource "aws_security_group" "alb" {
  name        = "${var.name}-alb"
  description = "Load balancer: HTTPS from Cloudflare only"
  vpc_id      = var.vpc_id
  tags        = { Name = "${var.name}-alb" }
}

resource "aws_vpc_security_group_ingress_rule" "alb_https_v4" {
  for_each          = toset(var.cloudflare_ipv4)
  security_group_id = aws_security_group.alb.id
  description       = "HTTPS from Cloudflare"
  cidr_ipv4         = each.value
  ip_protocol       = "tcp"
  from_port         = 443
  to_port           = 443
}

resource "aws_vpc_security_group_egress_rule" "alb_to_app" {
  security_group_id            = aws_security_group.alb.id
  description                  = "To WURK's web containers"
  referenced_security_group_id = aws_security_group.app.id
  ip_protocol                  = "tcp"
  from_port                    = 8000
  to_port                      = 8000
}

resource "aws_security_group" "app" {
  name        = "${var.name}-app"
  description = "WURK containers"
  vpc_id      = var.vpc_id
  tags        = { Name = "${var.name}-app" }
}

resource "aws_vpc_security_group_ingress_rule" "app_from_alb" {
  security_group_id            = aws_security_group.app.id
  description                  = "Web from the load balancer"
  referenced_security_group_id = aws_security_group.alb.id
  ip_protocol                  = "tcp"
  from_port                    = 8000
  to_port                      = 8000
}

resource "aws_vpc_security_group_ingress_rule" "app_internal" {
  for_each                     = toset(["13714", "3310"])
  security_group_id            = aws_security_group.app.id
  description                  = "SSR and ClamAV between WURK's containers"
  referenced_security_group_id = aws_security_group.app.id
  ip_protocol                  = "tcp"
  from_port                    = tonumber(each.value)
  to_port                      = tonumber(each.value)
}

# Outbound: the database, Redis, AWS APIs and the providers WURK calls (payments, WhatsApp, AI, SMS) over HTTPS.
resource "aws_vpc_security_group_egress_rule" "app_out" {
  for_each          = { https = 443, mysql = 3306, redis = 6379, ssr = 13714, clamav = 3310, smtp = 587 }
  security_group_id = aws_security_group.app.id
  description       = "Outbound ${each.key}"
  cidr_ipv4         = each.key == "https" || each.key == "smtp" ? "0.0.0.0/0" : var.vpc_cidr
  ip_protocol       = "tcp"
  from_port         = each.value
  to_port           = each.value
}

# --- Load balancer ------------------------------------------------------------------------------------------------------

resource "aws_lb" "this" {
  #checkov:skip=CKV2_AWS_28:the WAF is Cloudflare's, in front of this load balancer (decision E)
  name                       = var.name
  load_balancer_type         = "application"
  internal                   = false
  subnets                    = var.public_subnet_ids
  security_groups            = [aws_security_group.alb.id]
  drop_invalid_header_fields = true
  enable_deletion_protection = var.deletion_protection
  idle_timeout               = 120 # long uploads (videos)

  access_logs {
    bucket  = var.logs_bucket
    prefix  = "alb"
    enabled = true
  }
}

resource "aws_lb_target_group" "serve" {
  name                 = "${var.name}-serve"
  port                 = 8000
  protocol             = "HTTP"
  target_type          = "ip"
  vpc_id               = var.vpc_id
  deregistration_delay = 30

  health_check {
    path                = "/health/ready"
    matcher             = "200"
    interval            = 15
    timeout             = 5
    healthy_threshold   = 2
    unhealthy_threshold = 3
  }
}

resource "aws_lb_listener" "https" {
  load_balancer_arn = aws_lb.this.arn
  port              = 443
  protocol          = "HTTPS"
  ssl_policy        = "ELBSecurityPolicy-TLS13-1-2-2021-06"
  certificate_arn   = var.certificate_arn

  default_action {
    type             = "forward"
    target_group_arn = aws_lb_target_group.serve.arn
  }
}

# --- Task definitions and services ------------------------------------------------------------------------------------

resource "aws_ecs_task_definition" "role" {
  for_each                 = local.roles
  family                   = "${var.name}-${each.key}"
  requires_compatibilities = ["FARGATE"]
  network_mode             = "awsvpc"
  cpu                      = each.value.cpu
  memory                   = each.value.memory
  execution_role_arn       = aws_iam_role.execution.arn
  task_role_arn            = aws_iam_role.task.arn

  runtime_platform {
    operating_system_family = "LINUX"
    cpu_architecture        = "X86_64"
  }

  container_definitions = jsonencode([{
    name                   = each.key
    image                  = local.image
    command                = each.value.command
    essential              = true
    readonlyRootFilesystem = false # Octane and Caddy write to storage/
    environment            = local.environment
    secrets                = local.secrets
    portMappings           = each.value.port == null ? [] : [{ containerPort = each.value.port, protocol = "tcp", name = each.key }]
    stopTimeout            = each.key == "worker" ? 120 : 30 # a worker finishes its job before it stops
    linuxParameters        = { initProcessEnabled = true }
    logConfiguration = {
      logDriver = "awslogs"
      options = {
        awslogs-group         = aws_cloudwatch_log_group.role[each.key].name
        awslogs-region        = data.aws_region.this.name
        awslogs-stream-prefix = each.key
      }
    }
  }])
}

resource "aws_ecs_task_definition" "clamav" {
  family                   = "${var.name}-clamav"
  requires_compatibilities = ["FARGATE"]
  network_mode             = "awsvpc"
  cpu                      = 512
  memory                   = 2048 # ClamAV needs about 1.5 GB for its virus list
  execution_role_arn       = aws_iam_role.execution.arn

  container_definitions = jsonencode([{
    name         = "clamav"
    image        = var.clamav_image
    essential    = true
    portMappings = [{ containerPort = 3310, protocol = "tcp", name = "clamav" }]
    logConfiguration = {
      logDriver = "awslogs"
      options = {
        awslogs-group         = aws_cloudwatch_log_group.role["clamav"].name
        awslogs-region        = data.aws_region.this.name
        awslogs-stream-prefix = "clamav"
      }
    }
  }])
}

locals {
  services = {
    serve     = { desired = var.serve.min, min_percent = 100, max_percent = 200, lb = true, discovery = false }
    worker    = { desired = var.worker.min, min_percent = 100, max_percent = 200, lb = false, discovery = false }
    scheduler = { desired = 1, min_percent = 0, max_percent = 100, lb = false, discovery = false } # never two at once
    ssr       = { desired = var.ssr_count, min_percent = 100, max_percent = 200, lb = false, discovery = true }
  }
}

resource "aws_service_discovery_service" "role" {
  for_each = toset(["ssr", "clamav"])
  name     = each.key
  dns_config {
    namespace_id   = aws_service_discovery_private_dns_namespace.this.id
    routing_policy = "MULTIVALUE"
    dns_records {
      ttl  = 10
      type = "A"
    }
  }
  health_check_custom_config {
    failure_threshold = 1
  }
}

resource "aws_ecs_service" "role" {
  for_each               = local.services
  name                   = each.key
  cluster                = aws_ecs_cluster.this.id
  task_definition        = aws_ecs_task_definition.role[each.key].arn
  desired_count          = each.value.desired
  launch_type            = "FARGATE"
  platform_version       = "LATEST"
  enable_execute_command = true # `aws ecs execute-command` for support, audited in CloudTrail
  propagate_tags         = "SERVICE"

  deployment_minimum_healthy_percent = each.value.min_percent
  deployment_maximum_percent         = each.value.max_percent
  health_check_grace_period_seconds  = each.value.lb ? 60 : null

  deployment_circuit_breaker {
    enable   = true
    rollback = true
  }

  network_configuration {
    subnets          = var.app_subnet_ids
    security_groups  = [aws_security_group.app.id]
    assign_public_ip = false
  }

  dynamic "load_balancer" {
    for_each = each.value.lb ? [1] : []
    content {
      target_group_arn = aws_lb_target_group.serve.arn
      container_name   = each.key
      container_port   = 8000
    }
  }

  dynamic "service_registries" {
    for_each = each.value.discovery ? [1] : []
    content {
      registry_arn = aws_service_discovery_service.role[each.key].arn
    }
  }

  lifecycle {
    ignore_changes = [desired_count, task_definition] # autoscaling owns the count; releases (scripts/deploy) the version
  }

  depends_on = [aws_lb_listener.https]
}

resource "aws_ecs_service" "clamav" {
  name                               = "clamav"
  cluster                            = aws_ecs_cluster.this.id
  task_definition                    = aws_ecs_task_definition.clamav.arn
  desired_count                      = 1
  launch_type                        = "FARGATE"
  deployment_minimum_healthy_percent = 100
  deployment_maximum_percent         = 200

  network_configuration {
    subnets          = var.app_subnet_ids
    security_groups  = [aws_security_group.app.id]
    assign_public_ip = false
  }

  service_registries {
    registry_arn = aws_service_discovery_service.role["clamav"].arn
  }
}

# --- Autoscaling --------------------------------------------------------------------------------------------------------

resource "aws_appautoscaling_target" "role" {
  for_each           = { serve = var.serve, worker = var.worker }
  service_namespace  = "ecs"
  resource_id        = "service/${aws_ecs_cluster.this.name}/${aws_ecs_service.role[each.key].name}"
  scalable_dimension = "ecs:service:DesiredCount"
  min_capacity       = each.value.min
  max_capacity       = each.value.max
}

resource "aws_appautoscaling_policy" "cpu" {
  for_each           = aws_appautoscaling_target.role
  name               = "${each.key}-cpu"
  policy_type        = "TargetTrackingScaling"
  service_namespace  = each.value.service_namespace
  resource_id        = each.value.resource_id
  scalable_dimension = each.value.scalable_dimension

  target_tracking_scaling_policy_configuration {
    target_value       = 60
    scale_in_cooldown  = 300
    scale_out_cooldown = 60
    predefined_metric_specification {
      predefined_metric_type = "ECSServiceAverageCPUUtilization"
    }
  }
}

resource "aws_appautoscaling_policy" "requests" {
  name               = "serve-requests"
  policy_type        = "TargetTrackingScaling"
  service_namespace  = aws_appautoscaling_target.role["serve"].service_namespace
  resource_id        = aws_appautoscaling_target.role["serve"].resource_id
  scalable_dimension = aws_appautoscaling_target.role["serve"].scalable_dimension

  target_tracking_scaling_policy_configuration {
    target_value       = var.serve.requests_per_task
    scale_in_cooldown  = 300
    scale_out_cooldown = 60
    predefined_metric_specification {
      predefined_metric_type = "ALBRequestCountPerTarget"
      resource_label         = "${aws_lb.this.arn_suffix}/${aws_lb_target_group.serve.arn_suffix}"
    }
  }
}
