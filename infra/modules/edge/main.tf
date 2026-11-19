# Cloudflare in front of WURK (Sprint 17.3): DNS for the environment's addresses, the load balancer's certificate
# (ACM, validated through Cloudflare DNS), and — in production — the zone's TLS settings and Cloudflare for SaaS's
# fallback origin for owners' own domains.
#
#   <root>, app., admin., my., help., *.  → the load balancer (proxied: WAF, CDN, DDoS protection)
#   origin.<root>                         → the load balancer (custom hostnames connect here; SNI = origin.<root>)
#   sites.<root>                          → origin.<root> (what owners point their CNAME at)
#   files.<root>                          → CloudFront (DNS only: CloudFront has its own certificate)

terraform {
  required_providers {
    aws        = { source = "hashicorp/aws", version = "~> 5.80", configuration_aliases = [aws.us_east_1] }
    cloudflare = { source = "cloudflare/cloudflare", version = "~> 4.52" }
  }
}

locals {
  proxied = toset(["", "app", "admin", "my", "help", "*", "origin"])
  name    = { for k in local.proxied : k => k == "" ? var.root_domain : "${k}.${var.root_domain}" }
}

data "cloudflare_ip_ranges" "this" {}

# --- The load balancer's certificate -----------------------------------------------------------------------------------

resource "aws_acm_certificate" "app" {
  domain_name               = var.root_domain
  subject_alternative_names = ["*.${var.root_domain}"]
  validation_method         = "DNS"
  key_algorithm             = "RSA_2048"
  lifecycle {
    create_before_destroy = true
  }
}

resource "cloudflare_record" "validation" {
  for_each = { for o in aws_acm_certificate.app.domain_validation_options : o.resource_record_name => o... }
  zone_id  = var.zone_id
  name     = trimsuffix(each.value[0].resource_record_name, ".")
  type     = each.value[0].resource_record_type
  content  = trimsuffix(each.value[0].resource_record_value, ".")
  ttl      = 60
  proxied  = false
  comment  = "ACM validation (${var.environment})"
}

resource "aws_acm_certificate_validation" "app" {
  certificate_arn         = aws_acm_certificate.app.arn
  validation_record_fqdns = [for r in cloudflare_record.validation : r.hostname]
}

# CloudFront's certificate for files.<root> must live in us-east-1.
resource "aws_acm_certificate" "files" {
  provider          = aws.us_east_1
  domain_name       = "files.${var.root_domain}"
  validation_method = "DNS"
  lifecycle {
    create_before_destroy = true
  }
}

resource "cloudflare_record" "files_validation" {
  for_each = { for o in aws_acm_certificate.files.domain_validation_options : o.resource_record_name => o... }
  zone_id  = var.zone_id
  name     = trimsuffix(each.value[0].resource_record_name, ".")
  type     = each.value[0].resource_record_type
  content  = trimsuffix(each.value[0].resource_record_value, ".")
  ttl      = 60
  proxied  = false
  comment  = "ACM validation, files (${var.environment})"
}

resource "aws_acm_certificate_validation" "files" {
  provider                = aws.us_east_1
  certificate_arn         = aws_acm_certificate.files.arn
  validation_record_fqdns = [for r in cloudflare_record.files_validation : r.hostname]
}

# --- DNS ----------------------------------------------------------------------------------------------------------------

resource "cloudflare_record" "app" {
  for_each = var.load_balancer_dns == null ? {} : local.name
  zone_id  = var.zone_id
  name     = each.value
  type     = "CNAME"
  content  = var.load_balancer_dns
  proxied  = true
  comment  = "WURK ${var.environment}"
}

resource "cloudflare_record" "sites" {
  count   = var.load_balancer_dns == null ? 0 : 1
  zone_id = var.zone_id
  name    = "sites.${var.root_domain}"
  type    = "CNAME"
  content = "origin.${var.root_domain}"
  proxied = true
  comment = "Owners point their own domains here (Cloudflare for SaaS)"
}

resource "cloudflare_record" "files" {
  count   = var.files_cloudfront_domain == null ? 0 : 1
  zone_id = var.zone_id
  name    = "files.${var.root_domain}"
  type    = "CNAME"
  content = var.files_cloudfront_domain
  proxied = false
  comment = "Public website files (CloudFront)"
}

# --- Zone-wide settings (production only: staging shares the zone) -------------------------------------------------------

resource "cloudflare_custom_hostname_fallback_origin" "this" {
  count   = var.manage_zone ? 1 : 0
  zone_id = var.zone_id
  origin  = "origin.${var.root_domain}"

  depends_on = [cloudflare_record.app]
}

resource "cloudflare_zone_settings_override" "this" {
  count   = var.manage_zone ? 1 : 0
  zone_id = var.zone_id
  settings {
    ssl                      = "strict" # Full (strict): the load balancer's certificate is checked
    always_use_https         = "on"
    min_tls_version          = "1.2"
    tls_1_3                  = "on"
    automatic_https_rewrites = "on"
    http3                    = "on"
    brotli                   = "on"
    security_level           = "medium"
    browser_check            = "on"
  }
}
