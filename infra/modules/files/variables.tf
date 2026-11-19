variable "name" {
  type = string
}

variable "environment" {
  type = string
}

variable "kms_key_arn" {
  type = string
}

variable "noncurrent_version_days" {
  description = "How long overwritten or deleted files can be brought back"
  type        = number
  default     = 90
}

variable "log_retention_days" {
  type    = number
  default = 90
}

variable "elb_account_id" {
  description = "The Elastic Load Balancing account for the region (af-south-1: 098369216593)"
  type        = string
  default     = "098369216593"
}

variable "public_domain" {
  description = "Address for public website files, e.g. files.wurk.co.za (null: CloudFront's own address)"
  type        = string
  default     = null
}

variable "public_certificate_arn" {
  description = "ACM certificate in us-east-1 for public_domain (CloudFront needs it there)"
  type        = string
  default     = null
}
