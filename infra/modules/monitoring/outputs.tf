output "topic_arn" {
  value = aws_sns_topic.alarms.arn
}

output "token" {
  description = "WATCHTOWER_ALARM_TOKEN"
  value       = random_password.token.result
  sensitive   = true
}
