---
name: granted-asset-producer
description: Uses only approved local SVGs and CSS tokens for GRANTED. No new art direction.
---

# GRANTED Asset Producer

Job: wire existing assets; do not redesign the product.

## Allowed assets
- assets/icons/*.svg only (upload, settings, log-out, search, file-text, users, circle-check-big, circle-alert, layout-list, inbox, …)
- Prefer icon helper in includes/functions.php if present
- currentColor / CSS for light header vs dark body

## Forbidden
- External CDN icons
- New icon packs without user approval
- Raster marketing illustrations unless user provides files
- Redesigning palette or logo from scratch

## Output
Implement in PHP/CSS; list which SVG each UI control uses. Stop when wired and consistent.