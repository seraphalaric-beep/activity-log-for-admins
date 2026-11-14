# Activity Log admin design

The interface sits inside the existing WordPress admin. The Activity tab puts search and filters above a compact table. Change summaries lead every row. User, time, and severity support scanning, while the event panel holds exact times, previous and new values, and revision links.

The [WordPress Design Standard](docs/WordPress%20Design%20Standard.md) defines the plugin brand. Manrope, charcoal text, deep indigo controls, and white panels give the Activity screen, event details, Settings, and the dashboard widget a consistent appearance. The activity table gives each change a separate row with space above and below. In the dashboard widget, the counts carry the greatest weight and recent event titles use regular weight.

Severity badges use traffic light colours and keep their text labels. A legend explains Info as routine activity, Notice as a noteworthy change, and Warning as needing attention. Lucide icons identify event types and reinforce actions. Official shadcn/ui primitives provide the table, controls, badges, and dialog.

At narrow widths, each event retains its actor, time, and severity below the summary. The event panel uses the full screen when space is limited. Escape closes it and returns focus to the selected event. The panel respects reduced motion preferences.
