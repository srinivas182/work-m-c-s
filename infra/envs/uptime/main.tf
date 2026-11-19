# WURK seen from outside (Sprint 17.5, Sprint 18's decision G): Better Stack checks WURK every few minutes from
# several places and tells the on-call person when it can't reach it — the one alarm that still works when all of
# WURK (and its own alerting) is down. Its incidents also go to WURK's uptime webhook (/_wurk/uptime/<token>), so
# Watchtower records how long an outage lasted.
#
#   export BETTERUPTIME_API_TOKEN=…   # Better Stack → Uptime API token (never in a file)
#   tofu init -backend-config=bucket=wurk-tofu-state-<production account> && tofu apply

terraform {
  required_version = ">= 1.8.0"
  required_providers {
    betteruptime = { source = "BetterStackHQ/better-uptime", version = "~> 0.20" }
  }
  backend "s3" {
    region         = "af-south-1"
    key            = "uptime/terraform.tfstate"
    encrypt        = true
    dynamodb_table = "wurk-tofu-locks"
  }
}

provider "betteruptime" {}

variable "status_page" {
  description = "Publish status.wurk.co.za (a paid Better Stack feature)"
  type        = bool
  default     = false
}

locals {
  # name => [url, how often (seconds), expected answer]
  checks = {
    "WURK app (production)"                 = ["https://app.wurk.co.za/health/ready", 60, 200]
    "WURK scheduler heartbeat (production)" = ["https://app.wurk.co.za/health/heartbeat", 180, 200]
    "WURK website (production)"             = ["https://wurk.co.za/", 60, 200]
    "WURK app (staging)"                    = ["https://app.staging.wurk.co.za/health/ready", 300, 200]
  }
}

resource "betteruptime_monitor" "this" {
  for_each              = local.checks
  pronounceable_name    = each.key
  url                   = each.value[0]
  monitor_type          = "expected_status_code"
  expected_status_codes = [each.value[2]]
  check_frequency       = each.value[1]
  request_timeout       = 15
  confirmation_period   = 60 # two failed checks before anyone is woken
  recovery_period       = 120
  regions               = ["eu", "us", "as", "au"]
  email                 = true
  sms                   = !strcontains(each.key, "staging")
  verify_ssl            = true
  ssl_expiration        = 14 # warn two weeks before a certificate would expire
}

resource "betteruptime_status_page" "this" {
  count         = var.status_page ? 1 : 0
  company_name  = "WURK"
  company_url   = "https://wurk.co.za"
  subdomain     = "wurk"
  custom_domain = "status.wurk.co.za"
  timezone      = "Pretoria"
}

resource "betteruptime_status_page_resource" "this" {
  for_each       = var.status_page ? { for k, m in betteruptime_monitor.this : k => m if !strcontains(k, "staging") } : {}
  status_page_id = betteruptime_status_page.this[0].id
  resource_id    = each.value.id
  resource_type  = "Monitor"
  public_name    = replace(each.key, " (production)", "")
}
