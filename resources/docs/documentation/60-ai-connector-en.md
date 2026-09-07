# AI Connector

The AI Connector lets an assistant you already use, Claude or ChatGPT, read your Whisper Money data and change it, so you can ask about your money in plain language instead of clicking through screens.

{{TOC}}

## Quick start

1. Open **Settings → AI Connector**.
2. Pick your app under **How to connect** and copy the URL it shows.
3. In Claude or ChatGPT, add that URL as a custom connector.
4. Sign in and approve the connection on the Whisper Money screen that opens.
5. Ask it something: "how much did I spend on groceries last month?"

On ChatGPT you can skip the URL and connect Whisper Money from its app directory
instead. Both paths are described below, along with why you might prefer one.

Do this part on a computer. Signing in and approving works fine in a desktop
browser but usually breaks in a phone's in-app browser. Once it is connected,
chatting with Whisper Money from your phone works as usual.

The AI Connector is part of the paid plan.

## What MCP is

MCP stands for Model Context Protocol. It is the standard way to give an AI
assistant access to an app's data, and it is what the AI Connector speaks.

You do not need to know anything about it to use this. What matters is what it
means in practice:

- The assistant does not get a copy of your database. It asks Whisper Money a
  question at the moment it needs an answer, and gets back only that answer.
- Every request is made as you. It sees exactly what your account sees, and
  nothing from anybody else's.
- You decide when the connection exists. Nothing is connected until you connect
  it, and removing it cuts access off at once.

Whisper Money exposes 32 of these questions and actions, called tools: 11 that
read your data and 21 that change it. The assistant picks the ones it needs on
its own; you write in plain language.

## What you can ask for

<div class="cards-wrapper">

<div class="card">
### Transactions

Search your history, and create, edit, delete, categorize, label, split and
merge transactions.

Try:

- "What did I spend at the supermarket in March?"
- "Note down 40 euros on groceries yesterday."
- "Split last night's dinner into food and drinks."

</div>

<div class="card">
### Accounts and balances

List your accounts with their balances, and record a new balance on an account
you track by value.

Try:

- "Which accounts do I have, and what is in them?"
- "My broker account is at 12,400 today."

</div>

<div class="card">
### Categories and labels

List, create, rename and delete your categories and labels.

Try:

- "Create a Pets category."
- "Which labels am I not really using?"

</div>

<div class="card">
### Budgets

See how the period in progress is going, and create, edit or delete a budget.

Try:

- "How am I doing on my budgets?"
- "Set up a 300 a month budget for eating out."

</div>

<div class="card">
### Automation rules

List your rules, write new ones, change them, and apply one to the transactions
already in your account.

Try:

- "Anything from Netflix should go to Subscriptions."
- "Apply that rule to my past transactions."

</div>

<div class="card">
### Cashflow, net worth and spending

The same figures the Cashflow and dashboard screens show, for any period you
ask about.

Try:

- "Where is my money going this year?"
- "Has my net worth grown since January?"

</div>

<div class="card">
### Spaces

List your personal space and any space shared with you, so you can ask about
one in particular.

Try:

- "How much has the household space spent this month?"

</div>

<div class="card">
### Medals

Read your Progress catalog: the medals you have earned, the one you are working
towards next, and your saving streak.

Try:

- "What is the next medal I can get?"

</div>
</div>

Nothing here is a command you have to remember. Ask in your own words and the
assistant works out which tools to use.

## Connect Claude

Works for Claude Desktop and for Claude on the web.

1. Open **Settings → Connectors** in Claude.
2. Click **Add** in the top right, then **Add custom connector**.
3. Give it a name and paste the URL from **Settings → AI Connector**, the one
   ending in `/mcp/oauth`.
4. Approve the connection on the Whisper Money screen that opens.

You sign in with your Whisper Money account and approve the connection on a
screen we serve. There is no token to create or paste, and Claude never sees
your password.

## Connect ChatGPT

There are two ways in. They are alternatives rather than steps, so pick one.

### From ChatGPT's app directory

Whisper Money is an approved app in ChatGPT, so this is the short path and needs
no developer mode.

1. Find Whisper Money in ChatGPT's app directory and connect it there.
2. Sign in and approve the connection on the Whisper Money screen that opens.

If it does not turn up in the directory for you, use the custom connector below
instead. It does not depend on the listing.

### As a custom connector

This is the one to use if you want everything the connector can do today.

The approved listing updates slowly. A tool that already works here can take
weeks to appear in it, so the two paths do not always offer the same thing: a
custom connector points straight at our server and always has the current
version, while the directory listing is simply the more convenient one.

1. Turn on developer mode: **Settings → Security and login → Developer mode**.
2. In **Plugins**, click the **+** button in the top right.
3. Give it a name and paste the same URL ending in `/mcp/oauth`.
4. Approve the connection on the Whisper Money screen that opens.

## Connect Claude Code

Claude Code is the developer command line, and it signs in with a token instead
of the browser flow. Everyone else should use one of the two sections above.

Open the **Connect with Claude Code** section in **Settings → AI Connector**,
create a token, and run:

```text
claude mcp add --transport http whisper-money https://whisper.money/mcp --header "Authorization: Bearer <token>"
```

Copy the exact command from the page rather than this one, and put your own
token in place of `<token>`.

### Tokens

A token is a password for one connection. Two things to choose when you create
one:

- **Read only** can search, analyse and report, and can never change anything.
- **Read & write** can also create, edit and delete transactions, categories,
  labels, budgets and automation rules.

A token is shown once, when you create it, so copy it somewhere safe right then.
The page keeps the name, the access level, and the dates it was created and last
used, which is how you tell a live token from one you forgot about.

Two things you can do to a token later:

- **Rotate** it if it leaks. It keeps its name and access level and gets a fresh
  secret, and anything using the old one stops working until you reconnect it.
- **Revoke** it to cut access off. That takes effect immediately and cannot be
  undone.

Tokens are only for Claude Code. A Claude or ChatGPT connection has no token
behind it, so there is nothing to rotate and nothing to leak.

## What it can and cannot change

A connection made from Claude or ChatGPT can read your data and change it. A
Claude Code connection can change it only if its token is read & write.

Where write access stops is the same for both, and it is not about trust. These
are the rules the app itself follows:

- **Bank-synced and imported transactions cannot be edited or deleted.** Only
  transactions created by hand can. Any transaction, however it arrived, can
  still be categorized, labelled and split.
- **New transactions can be added to any account**, a bank-connected one
  included. A sync only brings in rows it has not seen before, so it never
  removes what you added.
- **Balances can only be recorded on accounts that are not connected to a
  bank.** A connected account's balance comes from the bank, and a figure
  written by hand would be overwritten by the next sync.
- **A budget's period, start day, rollover and tracked categories are fixed
  once it is created.** To change any of those, the budget has to be deleted
  and created again.
- **Applying an automation rule to past transactions asks first.** The
  assistant reports how many transactions match and changes nothing until you
  tell it to go ahead.

Deleting is real deleting, the same as pressing the button in the app. If you
would rather nothing be touched, connect Claude Code with a read-only token; a
Claude or ChatGPT connection is always read and write.

## Privacy

Whisper Money does not share your data with anyone. This is the one feature
where data leaves the app, so here is exactly what happens.

- Nothing is connected until you connect it yourself, from your own settings.
- While it is connected, the assistant reads your data in order to answer you.
  Those conversations live in your account with that assistant, under that
  company's rules, not ours. We cannot see them and we cannot control what they
  do with them.
- Only what your question needs is sent. Asking about last month's groceries
  does not hand over your whole history.
- When you want out, remove Whisper Money from the connected apps inside Claude
  or ChatGPT, or delete the token in Claude Code's case. It stops answering
  immediately.

Connect it only if you are comfortable with that trade. If you never connect it,
nothing about your account changes.

## FAQ

### Why does this need the paid plan?

Every question your assistant asks runs a real query against your data, on our
servers. The paid plan is what pays for that. The check happens on each request,
so it is not something you set up once and keep.

### Can I set it up on the free plan?

You can connect it and the connection will be made, but the answers will come
back asking you to upgrade. Nothing breaks and nothing is lost; it starts
working the moment you are on the paid plan.

### It worked and now it does not. What happened?

The most likely reason is that the subscription lapsed. The connection is still
there and still approved, but each request is checked against your plan, so it
stops answering rather than disconnecting. Renewing brings it back with nothing
to reconnect.

The other possibility, on Claude Code only, is a token that was rotated or
revoked. Add the connection again with the new secret.

### Can it lose or wreck my data?

It can change and delete the same things you can, so treat it the way you would
treat handing someone your laptop. Two things limit the damage: transactions
that came from your bank or an import cannot be edited or deleted at all, and a
read-only Claude Code token can analyse everything and change nothing.

### Should I use Claude Desktop or Claude Code?

Claude Desktop, unless you already live in a terminal. It is the same data and
the same tools either way. Claude Desktop signs you in through the browser and
needs no token; Claude Code needs a token you create and paste, and gives you
the read-only option in exchange.

### Why does connecting fail on my phone?

The sign-in and approval step opens in the in-app browser inside Claude or
ChatGPT, and that browser usually cannot complete it. Connect on a computer.
Afterwards the connection belongs to your account, so asking questions from your
phone works normally.

### Can I connect more than one assistant?

Yes. Claude and ChatGPT can both be connected at the same time, and Claude Code
alongside them with its own token. They are separate connections and removing
one leaves the others alone.

### How do I disconnect?

In Claude or ChatGPT, remove Whisper Money from the list of connected apps or
plugins. For Claude Code, revoke the token in **Settings → AI Connector**. There
is nothing to undo on our side afterwards.

### Is there a limit to how much I can ask?

There is a ceiling of 60 requests a minute, which is well above what a
conversation uses. A single question usually costs a handful of requests, so you
would only meet it if something were looping.

### Can I try it on the demo account?

No. The demo account is shared and its login is public, so it can never be
connected to an assistant.
