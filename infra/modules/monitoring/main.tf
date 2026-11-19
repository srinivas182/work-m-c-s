# Monitoring and alerts (Sprint 17.5).
# - CloudWatch alarms on what users feel (errors, slowness, no healthy web containers, no scheduler) and on what runs
#   out (database CPU, storage and memory; cache memory).
# - Every alarm goes to one SNS topic, delivered to WURK itself (POST /_wurk/alarms/<token>, signature-checked): each
#   becomes a Watchtower problem emailed to the people in Admin → Watchtower — addresses set in settings, not code.
# - In case WURK itself is down, the same topic can also email `alert_emails` (given when applying, never committed),
#   and Better Stack checks WURK from outside (better_stack.tf).

terraform {
  required_providers {
    aws    = { source = "hashicorp/aws", version = "~> 5.80" }
    random = { source = "hashicorp/random", version = "~> 3.6" }
  }
}

resource "random_password" "token" {
  length  = 48
  special = false
}

resource "aws_sns_topic" "alarms" {
  name              = "${var.name}-alarms"
  kms_master_key_id = var.kms_key_arn
}

resource "aws_sns_topic_policy" "alarms" {
  arn = aws_sns_topic.alarms.arn
  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{
      Sid       = "CloudWatchPublishes"
      Effect    = "Allow"
      Principal = { Service = "cloudwatch.amazonaws.com" }
      Action    = "sns:Publish"
      Resource  = aws_sns_topic.alarms.arn
    }]
  })
}

resource "aws_sns_topic_subscription" "wurk" {
  topic_arn              = aws_sns_topic.alarms.arn
  protocol               = "https"
  endpoint               = "https://${var.app_domain}/_wurk/alarms/${random_password.token.result}"
  endpoint_auto_confirms = true # WURK confirms it itself (CloudAlarms::confirm)
}

resource "aws_sns_topic_subscription" "email" {
  for_each  = toset(var.alert_emails)
  topic_arn = aws_sns_topic.alarms.arn
  protocol  = "email"
  endpoint  = each.value
}

locals {
  alb = { LoadBalancer = var.load_balancer_arn_suffix }
  tg  = { LoadBalancer = var.load_balancer_arn_suffix, TargetGroup = var.target_group_arn_suffix }
  # name => [description, namespace, metric, statistic, dimensions, comparison, threshold, periods, datapoints]
  simple = merge({
    "crit-no-healthy-web" = ["No healthy web containers behind the load balancer", "AWS/ApplicationELB", "HealthyHostCount", "Minimum", local.tg, "LessThanThreshold", 1, 3, 3]
    "warn-slow-pages"     = ["Pages are slow: 95% of requests take more than 2 seconds", "AWS/ApplicationELB", "TargetResponseTime", "p95", local.tg, "GreaterThanThreshold", 2, 10, 8]
    "crit-no-scheduler"   = ["The scheduler isn't running (reminders, backups' checks, campaigns stop)", "ECS/ContainerInsights", "RunningTaskCount", "Minimum", { ClusterName = var.cluster_name, ServiceName = "scheduler" }, "LessThanThreshold", 1, 5, 5]
    "crit-no-workers"     = ["No queue workers are running (messages and jobs wait)", "ECS/ContainerInsights", "RunningTaskCount", "Minimum", { ClusterName = var.cluster_name, ServiceName = "worker" }, "LessThanThreshold", 1, 5, 5]
    "warn-cache-memory"   = ["The cache is more than 80% full", "AWS/ElastiCache", "DatabaseMemoryUsagePercentage", "Maximum", { ReplicationGroupId = var.cache_id }, "GreaterThanThreshold", 80, 10, 10]
    }, merge([for k, id in var.database_ids : {
      "warn-db-cpu-${k}"     = ["Database ${k}: CPU above 80% for 15 minutes", "AWS/RDS", "CPUUtilization", "Average", { DBInstanceIdentifier = id }, "GreaterThanThreshold", 80, 15, 15]
      "crit-db-storage-${k}" = ["Database ${k}: less than 5 GB free (storage grows by itself up to its limit)", "AWS/RDS", "FreeStorageSpace", "Minimum", { DBInstanceIdentifier = id }, "LessThanThreshold", 5368709120, 5, 5]
      "warn-db-memory-${k}"  = ["Database ${k}: less than 256 MB of memory free", "AWS/RDS", "FreeableMemory", "Minimum", { DBInstanceIdentifier = id }, "LessThanThreshold", 268435456, 10, 10]
  }]...))
}

resource "aws_cloudwatch_metric_alarm" "simple" {
  for_each            = local.simple
  alarm_name          = "${var.name}-${each.key}"
  alarm_description   = each.value[0]
  namespace           = each.value[1]
  metric_name         = each.value[2]
  statistic           = each.value[3] == "p95" ? null : each.value[3]
  extended_statistic  = each.value[3] == "p95" ? "p95" : null
  dimensions          = each.value[4]
  comparison_operator = each.value[5]
  threshold           = each.value[6]
  period              = 60
  evaluation_periods  = each.value[7]
  datapoints_to_alarm = each.value[8]
  treat_missing_data  = startswith(each.key, "crit-no-") ? "breaching" : "notBreaching"
  alarm_actions       = [aws_sns_topic.alarms.arn]
  ok_actions          = [aws_sns_topic.alarms.arn]
}

# More than 2% of requests failing (5xx from WURK or the load balancer) over 5 minutes, ignoring quiet minutes.
resource "aws_cloudwatch_metric_alarm" "errors" {
  alarm_name          = "${var.name}-crit-5xx"
  alarm_description   = "More than 2% of requests fail at the load balancer"
  comparison_operator = "GreaterThanThreshold"
  threshold           = 2
  evaluation_periods  = 5
  datapoints_to_alarm = 3
  treat_missing_data  = "notBreaching"
  alarm_actions       = [aws_sns_topic.alarms.arn]
  ok_actions          = [aws_sns_topic.alarms.arn]

  metric_query {
    id          = "rate"
    expression  = "IF(requests > 50, 100 * (FILL(target, 0) + FILL(lb, 0)) / requests, 0)"
    label       = "5xx %"
    return_data = true
  }
  metric_query {
    id = "requests"
    metric {
      namespace   = "AWS/ApplicationELB"
      metric_name = "RequestCount"
      dimensions  = local.alb
      period      = 60
      stat        = "Sum"
    }
  }
  metric_query {
    id = "target"
    metric {
      namespace   = "AWS/ApplicationELB"
      metric_name = "HTTPCode_Target_5XX_Count"
      dimensions  = local.alb
      period      = 60
      stat        = "Sum"
    }
  }
  metric_query {
    id = "lb"
    metric {
      namespace   = "AWS/ApplicationELB"
      metric_name = "HTTPCode_ELB_5XX_Count"
      dimensions  = local.alb
      period      = 60
      stat        = "Sum"
    }
  }
}
