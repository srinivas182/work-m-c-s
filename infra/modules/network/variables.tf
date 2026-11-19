variable "name" {
  description = "Name prefix, e.g. wurk-production"
  type        = string
}

variable "region" {
  description = "AWS region (af-south-1)"
  type        = string
}

variable "cidr" {
  description = "The network's address range (a /16)"
  type        = string
  default     = "10.40.0.0/16"
}

variable "single_nat_gateway" {
  description = "One NAT gateway for all zones (staging) instead of one per zone (production)"
  type        = bool
  default     = false
}

variable "log_retention_days" {
  description = "How long flow logs are kept"
  type        = number
  default     = 30
}

variable "kms_key_arn" {
  description = "Key that encrypts the logs"
  type        = string
}
