output "vault_arn" {
  value = aws_backup_vault.this.arn
}

output "role_arn" {
  description = "Runs backups and restores"
  value       = aws_iam_role.backup.arn
}
