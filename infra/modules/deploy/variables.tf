variable "name" {
  type = string
}

variable "environment" {
  type = string
}

variable "github_repository" {
  description = "owner/repo allowed to deploy"
  type        = string
}

variable "create_oidc_provider" {
  description = "Create GitHub's OIDC provider in this account (false when the account already has one)"
  type        = bool
  default     = true
}

variable "cluster_name" {
  type = string
}

variable "repository_arn" {
  type = string
}

variable "kms_key_arn" {
  type = string
}

variable "task_role_arns" {
  type = list(string)
}

variable "database_arns" {
  type = list(string)
}

variable "source_repository_arn" {
  description = "Staging's registry, which production copies tested images from (null in staging)"
  type        = string
  default     = null
}
