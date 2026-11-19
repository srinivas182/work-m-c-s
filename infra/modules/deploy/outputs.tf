output "role_arn" {
  description = "Set as the GitHub environment variable AWS_DEPLOY_ROLE_ARN"
  value       = aws_iam_role.deploy.arn
}
