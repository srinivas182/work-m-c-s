variable "name" {
  type = string
}

variable "kms_key_arn" {
  type = string
}

variable "retention_days" {
  description = "How long the daily backups are kept in this account"
  type        = number
}

variable "offsite_vault_arn" {
  description = "The locked vault in WURK's backups account (null: no off-site copy, e.g. before it exists)"
  type        = string
  default     = null
}

variable "offsite_retention_days" {
  type    = number
  default = 90
}

variable "database_arns" {
  type = list(string)
}

variable "files_bucket_arn" {
  type = string
}

variable "cluster_arn" {
  type = string
}

variable "release_family" {
  description = "The release task definition family (runs system:backup-taken)"
  type        = string
}

variable "task_role_arns" {
  type = list(string)
}

variable "app_subnet_ids" {
  type = list(string)
}

variable "app_security_group_id" {
  type = string
}
