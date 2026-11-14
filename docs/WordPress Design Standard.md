# WordPress Design Standard

Use this as the default for new WordPress plugin interfaces. Activity Log for Admins is the reference implementation. Apply the same design language to plugin pages, Settings, dialogs, and widgets. Respect the native WordPress shell, and use the site's brand when the user specifies one.

## Colour and surfaces

| Role | Colour |
| --- | --- |
| Page and grouped surface tint | `#F4F5F7` |
| Panel surface | `#FFFFFF` |
| Main text | `#192133` |
| Secondary text and metadata | `#4B5568` |
| Subtle dividers | `#E0E3EA` |
| Control border | `#9BA2B1` |
| Indigo for primary actions, links, active navigation, and focus | `#464E9C` |
| Indigo hover | `#373E83` |
| Indigo tint for selection and icons | `#EEF0FA` |

Use white text on primary indigo buttons. Keep headings and body copy charcoal. Use one light border around each panel, with 12 px corners. Separate rows with a single light divider. Use neutral tints to group related information and keep secondary controls neutral.

## Typography and hierarchy

Use the locally bundled Manrope variable font with a system sans fallback. Include the font licence in distributed builds. Use sentence case. Start with 14 px body and control text at 1.5 line height and 400 weight. Set the page title at 26 to 34 px and 700 weight, drawer titles at 24 px, section headings at 17 px, and most labels and actions at 600 weight. Use 12 px regular text for metadata. Where numbers are the main information, use 26 px at 700 weight and tabular numerals. Use regular weight for dashboard event titles, captions, usernames, and dates.

## Spacing and dimensions

| Element | Default |
| --- | --- |
| Desktop page inset | 32 px |
| Panel padding and section gaps | 24 px |
| Gap between controls | 8 to 12 px |
| Table heading height | 44 px |
| Activity row padding | 20 px above and below, with content allowed to wrap and grow |
| Summary list row padding | 16 px above and below |
| Settings and detail row padding | 14 px above and below |
| Gap between an item title and its metadata | 6 px |
| Primary controls | At least 44 px high, 16 px horizontal button padding, 8 px corners |
| Narrow panel inset | 16 px as a starting point |

Give the first row the same breathing room as later rows. Group labels with their own values and leave more space between sections than within an item. Align disclosure arrows with the user name on tables. Allow text, timestamps, and badges to wrap at narrow widths. Stack the summary metrics when the actual widget width is constrained.

## Components and behaviour

Use official shadcn/ui primitives for plugin owned React interfaces, with existing or official icon assets. Use native WordPress containers and capabilities where WordPress owns the surface. Scope all styling to the plugin. Use semantic tables and lists, explicit form labels, and a clearly marked active tab. Show the installed version in the Settings footer, using the plugin metadata as the source of truth.

Use green Info for routine activity, amber Notice for noteworthy change, and red Warning for items needing attention. Keep labels visible and explain them in a legend below the activity list. The Activity Log badge foreground and fill pairs are `#1B5E20` and `#E8F5E9`, `#7A4B00` and `#FFF8E1`, and `#8C1D18` and `#FFEBEE`.

Provide visible keyboard focus, meaningful link and button names, and text for status, errors, and empty states. Check contrast for small text and controls. Let Escape close the event drawer and return focus to its trigger. Respect reduced motion.

## Review

Review an actual desktop render and a narrow render with realistic content, including long names and changes. Check row spacing, type hierarchy, font loading, date and badge wrapping, keyboard focus, and the Settings version. Exercise the controls used to reach each changed view before marking the change complete.
