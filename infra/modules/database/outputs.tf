output "endpoints" {
  description = "Each database's address"
  value       = { for k, db in aws_db_instance.this : k => db.address }
}

output "instance_ids" {
  value = { for k, db in aws_db_instance.this : k => db.identifier }
}

output "instance_arns" {
  value = { for k, db in aws_db_instance.this : k => db.arn }
}

output "secret_arn" {
  description = "Secrets Manager secret with every database's credentials"
  value       = aws_secretsmanager_secret.db.arn
}

output "security_group_id" {
  value = aws_security_group.db.id
}
