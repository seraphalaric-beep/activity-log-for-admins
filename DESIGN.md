# Activity Log admin design

The interface sits inside the existing WordPress admin. The Activity tab puts search and filters above a compact table. Change summaries lead every row. Actor, time, and severity support scanning, while the event panel holds exact times, previous and new values, and revision links.

The interface uses system typography, navy text, a single blue action colour, and subtle blue grey dividers. Severity badges carry text labels. Lucide icons identify event types and reinforce actions. Official shadcn/ui primitives provide the table, controls, badges, and dialog.

At narrow widths, each event retains its actor, time, and severity below the summary. The event panel uses the full screen when space is limited. Escape closes it and returns focus to the selected event. The panel respects reduced motion preferences.
