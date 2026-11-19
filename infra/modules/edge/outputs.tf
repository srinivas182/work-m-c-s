output "certificate_arn" {
  value = aws_acm_certificate_validation.app.certificate_arn
}

output "files_certificate_arn" {
  value = aws_acm_certificate_validation.files.certificate_arn
}

output "cloudflare_ipv4" {
  description = "Cloudflare's IPv4 ranges, read from Cloudflare's API"
  value       = data.cloudflare_ip_ranges.this.ipv4_cidr_blocks
}

output "custom_domain_target" {
  value = "sites.${var.root_domain}"
}
