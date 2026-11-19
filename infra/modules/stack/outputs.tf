output "load_balancer_dns" {
  description = "Cloudflare's origin (17.3)"
  value       = module.app.load_balancer_dns
}

output "repository_url" {
  value = module.app.repository_url
}

output "cluster_name" {
  value = module.app.cluster_name
}

output "release_task_definition" {
  value = module.app.release_task_definition
}

output "app_subnet_ids" {
  value = module.app.app_subnet_ids
}

output "app_security_group_id" {
  value = module.app.app_security_group_id
}

output "database_endpoints" {
  value = module.database.endpoints
}

output "database_secret_arn" {
  value = module.database.secret_arn
}

output "app_secret_arn" {
  value = module.app.app_secret_arn
}

output "files_bucket" {
  value = module.files.bucket
}

output "files_cloudfront_domain" {
  value = module.files.cloudfront_domain
}

output "outbound_ips" {
  value = module.network.nat_public_ips
}

output "deploy_role_arn" {
  description = "GitHub environment variable AWS_DEPLOY_ROLE_ARN for this environment"
  value       = module.deploy.role_arn
}

output "repository_arn" {
  value = module.app.repository_arn
}
