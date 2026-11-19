output "primary_endpoint" {
  value = aws_elasticache_replication_group.this.primary_endpoint_address
}

output "auth_token" {
  value     = random_password.auth.result
  sensitive = true
}

output "replication_group_id" {
  value = aws_elasticache_replication_group.this.id
}
