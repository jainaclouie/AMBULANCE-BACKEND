<?php

return [
    // Seconds a crew has to accept an offered emergency before it is escalated.
    'offer_ttl_seconds' => (int) env('DISPATCH_OFFER_TTL', 60),

    // A unit only counts as "available" if its GPS was updated within this many seconds.
    'gps_fresh_seconds' => (int) env('DISPATCH_GPS_FRESH_SECONDS', 120),

    // When a crew declines or does not answer, automatically offer to the next-best unit.
    'auto_escalate' => filter_var(env('DISPATCH_AUTO_ESCALATE', true), FILTER_VALIDATE_BOOL),

    // Maximum number of units offered one incident before the dispatcher must step in.
    'max_offers' => (int) env('DISPATCH_MAX_OFFERS', 5),

    // Prefer the unit based in the incident's barangay if it is within this many seconds of the best.
    'home_unit_bonus_seconds' => (int) env('DISPATCH_HOME_BONUS', 60),

    // Routing: OSRM server (public demo by default; self-host for production).
    'osrm_url' => rtrim(env('OSRM_URL', 'https://router.project-osrm.org'), '/'),
    'osrm_timeout' => (int) env('OSRM_TIMEOUT', 5),

    // Used only when routing is unreachable: straight-line distance x factor at this speed.
    'fallback_road_factor' => 1.4,
    'fallback_speed_kmh' => 30,

    // Recalculate the active route when it is older than this many seconds.
    'route_refresh_seconds' => (int) env('DISPATCH_ROUTE_REFRESH', 120),
];
