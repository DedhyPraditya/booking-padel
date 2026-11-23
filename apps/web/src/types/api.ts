export interface ApiEnvelope<T, M = Record<string, unknown>> {
  data: T
  meta: M
}

export interface ApiValidationError {
  message: string
  errors?: Record<string, string[]>
}

export interface Court {
  id: string
  venue_id: string
  name: string
  code: string
  court_type: 'indoor' | 'semi_indoor' | 'outdoor'
  surface_type: string
  description: string | null
  status: 'active' | 'inactive' | 'maintenance'
}

export interface OperatingHour {
  id?: string
  venue_id: string
  day_of_week: number
  open_time: string
  close_time: string
  is_closed: boolean
}

export interface SpecialDate {
  id: string
  venue_id: string
  date: string
  is_closed: boolean
  open_time: string | null
  close_time: string | null
  note: string | null
}

export interface CourtBlock {
  id: string
  venue_id: string
  court_id: string
  start_at: string
  end_at: string
  reason: string
  status: 'active' | 'cancelled'
  court?: Pick<Court, 'id' | 'venue_id' | 'name' | 'code'>
}

export interface PricingRule {
  id: string
  venue_id: string
  court_id: string | null
  name: string
  day_of_week: number | null
  start_time: string
  end_time: string
  price_per_hour: string
  valid_from: string | null
  valid_until: string | null
  priority: number
  is_active: boolean
  court?: Pick<Court, 'id' | 'venue_id' | 'name' | 'code'> | null
}

export interface Venue {
  id: string
  organization_id?: string
  name: string
  slug: string
  address: string
  city: string
  timezone: string
  phone: string
  bank_name?: string
  bank_account_number?: string
  bank_account_holder?: string
  description: string | null
  status?: 'active' | 'inactive'
  slot_interval_minutes: number
  min_booking_duration_minutes: number
  max_booking_duration_minutes: number
  buffer_minutes?: number
  hold_duration_minutes?: number
  cancellation_cutoff_hours?: number
  reschedule_cutoff_hours?: number
  courts_count?: number
  starting_price_per_hour?: string | null
  courts?: Court[]
  operating_hours?: OperatingHour[]
  pricing_rules?: PricingRule[]
}

export interface AvailabilitySlot {
  start_at: string
  end_at: string
  start_time: string
  end_time: string
  available: boolean
  status: 'available' | 'held' | 'booked' | 'blocked' | 'maintenance' | 'unpriced' | 'past'
  price: number | null
}

export interface CourtAvailability {
  court_id: string
  court_name: string
  court_code: string
  court_type: string
  status: string
  slots: AvailabilitySlot[]
}

export interface DailyAvailability {
  venue_id: string
  venue_name: string
  date: string
  timezone: string
  duration_minutes: number
  slot_interval_minutes: number
  is_closed: boolean
  closed_reason: string | null
  courts: CourtAvailability[]
}

export interface PriceQuoteLineItem {
  court_id: string
  court_name: string
  court_code: string
  start_at: string
  end_at: string
  start_time: string
  end_time: string
  duration_minutes: number
  unit_price_per_hour: string
  total_price: string
}

export interface PriceQuote {
  venue_id: string
  court_id: string
  court_name: string
  court_code: string
  start_at: string
  end_at: string
  timezone: string
  duration_minutes: number
  unit_price_per_hour: string
  subtotal: string
  discount_amount: string
  tax_amount: string
  total_amount: string
  currency: string
  items?: PriceQuoteLineItem[]
  applied_rules: Array<{
    rule_id: string
    rule_name: string
    start: string
    end: string
    minutes: number
    price_per_hour: string
    slice_total: string
  }>
}

export interface BookingItem {
  id: string
  booking_id: string
  court_id: string
  court_name_snapshot: string
  court_code_snapshot: string
  start_at: string
  end_at: string
  duration_minutes: number
  unit_price_per_hour: string
  total_price: string
}

export interface Payment {
  id: string
  booking_id: string
  provider: string
  provider_reference: string | null
  payment_method: string
  amount: string
  currency: string
  status:
    | 'unpaid'
    | 'pending'
    | 'paid'
    | 'failed'
    | 'expired'
    | 'refund_pending'
    | 'partially_refunded'
    | 'refunded'
  has_proof: boolean
  proof_original_name: string | null
  proof_mime_type: string | null
  proof_uploaded_at: string | null
  sender_bank_name: string | null
  sender_account_holder: string | null
  verification_notes: string | null
  verified_at: string | null
  paid_at: string | null
  payment_instructions_json?: {
    bank_name?: string
    bank_account_number?: string
    bank_account_holder?: string
    whatsapp_admin?: string
    booking_code?: string
    amount?: string
  } | null
  booking?: Booking
}

export interface Booking {
  id: string
  booking_code: string
  venue_id: string
  customer_name: string
  customer_phone?: string
  customer_email?: string | null
  start_at: string
  end_at: string
  status:
    | 'pending_payment'
    | 'confirmed'
    | 'checked_in'
    | 'completed'
    | 'cancelled'
    | 'expired'
    | 'no_show'
    | 'refund_pending'
    | 'refunded'
  subtotal: string
  discount_amount: string
  tax_amount: string
  total_amount: string
  currency: string
  hold_expires_at: string | null
  notes?: string | null
  venue?: Pick<
    Venue,
    | 'id'
    | 'name'
    | 'slug'
    | 'timezone'
    | 'address'
    | 'city'
    | 'phone'
    | 'bank_name'
    | 'bank_account_number'
    | 'bank_account_holder'
  >
  items?: BookingItem[]
  payments?: Payment[]
}

export interface UserRoleAssignment {
  role: string
  organization_id: string | null
  venue_id: string | null
}

export interface AuthenticatedUser {
  id: string
  name: string
  email: string | null
  phone: string
  is_active: boolean
  roles: UserRoleAssignment[]
  permissions: string[]
  accessible_venue_ids: string[]
}
