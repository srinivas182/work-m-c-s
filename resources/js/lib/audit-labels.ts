/** Plain-language names for audit actions; unknown actions fall back to the raw key. */
export const AUDIT_LABELS: Record<string, string> = {
  'identity.signed_in': 'Signed in',
  'identity.sign_in_failed': 'Sign-in failed',
  'identity.code_requested': 'Sign-in code requested',
  'identity.mfa_enabled': 'Authenticator turned on',
  'identity.mfa_disabled': 'Authenticator turned off',
  'identity.password_changed': 'Password changed',
  'identity.sessions_revoked': 'Signed out other devices',
  'identity.signed_out': 'Signed out',
  'identity.staff_granted': 'WURK staff access granted',
  'identity.membership_granted': 'Added to a business',
  'tenant.created': 'Business created',
  'tenant.status_changed': 'Business status changed',
  'role.assigned': 'Role given',
  'role.revoked': 'Role removed',
  'role.created': 'Custom role created',
  'role.updated': 'Custom role changed',
  'role.deleted': 'Custom role deleted',
  'member.branches_changed': 'Branch access changed',
  'platform_role.granted': 'Staff role given',
  'platform_role.revoked': 'Staff role removed',
  'platform_role.created': 'Staff role created',
  'platform_role.deleted': 'Staff role deleted',
  'staff.deactivated': 'Staff member deactivated',
  'staff.reactivated': 'Staff member reactivated',
};

/** Readable label for an audit action, with a sensible fallback for new actions. */
export function auditLabel(action: string): string {
  return (
    AUDIT_LABELS[action] ?? action.replace(/[._]/g, ' ').replace(/^\w/, (c) => c.toUpperCase())
  );
}
