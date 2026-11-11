# Custom dashboards

Next to the dashboard every account has, you can build dashboards of your own:
pick the blocks you want, size them and put them in the order you read them.
Your AI assistant can also write new blocks for them, called widgets, from a
plain request such as "a chart of my groceries by month".

{{TOC}}

## Quick start

1. Open **Dashboard** and click the dashboard name next to the heading
   (**Overview** at first).
2. Choose **New dashboard**, give it a name and press **Create**.
3. Press **Add widget** and pick the blocks you want.
4. Drag each block by its handle into place, and choose its size from its menu.
5. Press **Done**.

To have your assistant write a widget, connect it first through the
[AI Connector](/documentation/ai-connector), then ask: "Make me a widget on my
Spending dashboard with my supermarket spending by month."

Custom dashboards are part of the Standard plan.

## Your dashboards

The name next to the **Dashboard** heading switches between them. **Overview**
is the dashboard every account has, the one described in
[Dashboard](/documentation/dashboard); it cannot be changed or deleted. Under it
come your own, up to 20.

**Manage dashboards**, in the same menu, lists them:

- The star makes one the **default**: the one that opens when you go to
  Dashboard. Without one, Overview opens.
- The pencil renames it and the bin deletes it, with the widgets placed on it.
  Deleting a dashboard does not delete the widgets your assistant wrote: they
  stay in your list, ready to place on another one.
- The handles put the dashboards in the order the menu lists them.

## Widgets and their sizes

A dashboard can hold the blocks of Overview (net worth, accounts, monthly
savings, top categories, top labels and cashflow) and the widgets your assistant
wrote, up to 50 in all.

Press **Edit** to change it. Each block then gets a bar to drag it, a menu to
size it and a cross to take it off the dashboard. Taking a block off does not
delete it: you can add it again later from **Add widget**.

Every block has one of a few fixed sizes, so all dashboards line up:

| Width          | Takes              | Height     | Takes    |
| -------------- | ------------------ | ---------- | -------- |
| **A third**    | A third of the row | **Medium** | One row  |
| **Half**       | Half the row       | **Large**  | Two rows |
| **Full width** | The whole row      |            |          |

A large block is exactly as tall as two medium ones stacked. Some blocks only
admit a few of these sizes (a wide table does not fit in a third), and the menu
only offers those. On a tablet a third takes half the row, and on a phone every
block takes the full width and keeps its height.

## Widgets your assistant writes

With the [AI Connector](/documentation/ai-connector) connected, your assistant
can write a widget for you. Describe what you want to see, and on which
dashboard:

- "Add a widget with my supermarket spending by month to my Spending
  dashboard."
- "Make a table of my five largest expenses this month, full width."
- "Change the groceries widget to show the last 6 months only."

The assistant writes the widget's code, says which of your data it reads, and
places it on the dashboard. Open the dashboard to see it; your assistant may
also show you a preview inside the chat. Ask for changes in the same way, and
the next time you open the dashboard the widget has them.

Your assistant can also list your dashboards, create new ones and take a widget
off a dashboard. Renaming, ordering and deleting dashboards is done in the app.

A widget is written in the language you asked in: it does not switch when you
change the app's language.

### When a widget breaks

If a widget fails while loading or drawing, its place on the dashboard says so
and the rest of the dashboard keeps working. The error is kept with the
widget, so you can simply tell your assistant "the groceries widget is broken,
fix it": it reads the error and writes a new version.

A widget whose data could not be read says so in its place too. **Partial
data** means one of its lists was longer than a widget can read (10,000 rows),
and it is drawn with the first ones.

### Versions

Every change your assistant makes is kept as a new version, the last 50. To go
back, open the widget's menu, choose **See the error and versions**, and press
**Go back to this one** next to the version you want. Going back saves it as a
new version, so you can always return to the one you left.

### Safe mode

A widget that gets stuck can freeze the tab. If a dashboard did not finish
loading last time, it opens in **safe mode** the next time: your blocks from
Overview are shown, and the widgets your assistant wrote are not loaded. Remove
or fix the one causing trouble, then press **Load widgets**.

You can also open safe mode yourself by adding `?safe=1` to the dashboard's
address.

## Privacy

Widgets are built so that your data stays in Whisper Money:

- **A widget cannot reach the internet.** Its code is checked before it is
  saved, and anything that could send data out (a request, a link, an image
  from another site) is refused.
- **It only reads what you can read**, through Whisper Money itself, the same
  way the app does: your own data, never anybody else's. It cannot change
  anything.
- **It is yours.** Nobody else sees your dashboards or widgets, and they are
  deleted with your account.

Your assistant writes the widget's code, so the conversation where you ask for
it follows your assistant's rules, as described in the
[AI Connector's privacy section](/documentation/ai-connector#privacy). Once
saved, opening the widget sends nothing to your assistant.

**Privacy mode** blurs the amounts in widgets as it does in the rest of the app,
as long as the widget uses the amount style Whisper Money gives it, which your
assistant is asked to do. If a widget shows an amount in the clear, ask your
assistant to fix it.

## Plan

Custom dashboards and widgets are part of the Standard plan, and are not
available on the demo account.

If your plan ends, nothing is deleted:

- **Overview** opens as your dashboard again.
- Your own dashboards are still in the menu. Opening one shows its layout with
  every block blurred and a notice to upgrade: no data is loaded and no widget
  runs while you are off the plan.
- Creating a dashboard, and asking your assistant for a widget, tells you it is
  part of the Standard plan.

When you upgrade again, every dashboard and widget comes back as you left it.

## FAQ

### Can I write a widget myself?

Not from the app. Widgets are written by your assistant through the
[AI Connector](/documentation/ai-connector). You can place, size, remove and go
back to an earlier version of any of them.

### My assistant says it cannot create the widget. Why?

It tells you why. The usual reasons are that the widget reads data that does not
exist (a category that was deleted, for example) or uses something widgets are
not allowed to, such as a link to another site. It then writes a version that
works, or asks you what you meant.

### Is there a limit?

20 dashboards, 50 blocks on each, and 100 widgets written by your assistant.
