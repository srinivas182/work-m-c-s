output "cluster_name" {
  value = aws_ecs_cluster.this.name
}

output "repository_url" {
  value = aws_ecr_repository.wurk.repository_url
}

output "load_balancer_dns" {
  description = "Cloudflare's origin for the app and the sites"
  value       = aws_lb.this.dns_name
}

output "load_balancer_arn_suffix" {
  value = aws_lb.this.arn_suffix
}

output "target_group_arn_suffix" {
  value = aws_lb_target_group.serve.arn_suffix
}

output "app_security_group_id" {
  value = aws_security_group.app.id
}

output "app_subnet_ids" {
  value = var.app_subnet_ids
}

output "release_task_definition" {
  description = "Run as a one-off task for migrations and release steps (17.2)"
  value       = aws_ecs_task_definition.role["release"].family
}

output "app_secret_arn" {
  value = aws_secretsmanager_secret.app.arn
}

output "service_names" {
  value = concat([for k, s in aws_ecs_service.role : s.name], [aws_ecs_service.clamav.name])
}

output "repository_arn" {
  value = aws_ecr_repository.wurk.arn
}

output "task_role_arns" {
  description = "The roles release tasks and services run with (the deploy role may pass only these)"
  value       = [aws_iam_role.execution.arn, aws_iam_role.task.arn]
}
