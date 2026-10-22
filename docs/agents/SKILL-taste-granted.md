---
name: taste-granted
description: Constrained use of design taste for GRANTED office UI only.
---

# Taste for GRANTED (constrained)

Use taste / design-taste-frontend ideas ONLY under this brief.

## Product
GRANTED — University of Luzon scholarship compliance (admin + scholar).
PHP + existing CSS. Office tool, not a marketing site.

## Locked (do not replace)
- Maroon #A8180D, gold #C79900, bg #F7F7F7, surface #FFF, text #171717
- Top nav + tables; local assets/icons/*.svg only
- No new palette, no new display font, no stock photography heroes

## Allowed from taste
- Anti-slop (no generic SaaS cards, no gradient washes, no fake metrics)
- Quiet panels, alignment, spacing, hierarchy
- App-screen discipline (data readable; chrome calm)
- Light motion only if user asks; respect prefers-reduced-motion

## Forbidden
- Full art-direction “deal me a new style”
- Landing-page rebuilds
- Replacing UL identity
- Changing auth, CSRF, engine, schema

## Process
1. Read docs/agents/GRANTED-UI-BRIEF.md
2. One surface only (e.g. login OR document review)
3. Minimal diffs; list files + test steps