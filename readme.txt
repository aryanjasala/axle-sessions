=== Axle Sessions ===
Contributors: aryanjasala
Tags: sessions, security, login, logout, devices
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

See every device signed in to WordPress, sign out one of them, and limit sessions per role.

== Description ==

WordPress already keeps a record of every login: which browser, which IP address, when it started and when it ends. It just never shows it. The only control is "Log Out Everywhere Else" on your own profile, so nobody can see which devices are signed in, sign out just the lost phone, or see who is signed in across the site.

Axle Sessions shows those sessions and lets you end them.

**For everyone, on their profile:**

* **Your sessions**: every browser signed in to your account, with the device ("Safari on iOS"), IP address, when it signed in, when it was last active and when it expires. The one you are using is marked "This device".
* **Sign out** any one of them, or all of them except this one.
* **Sign-in history**: when and where the account signed in over the last 90 days, and how each session ended: signed out, expired, idle, signed out from another device, signed out by an administrator, device limit reached, reported as "This wasn't me", or password changed.
* A **message on the login screen** that says why you were signed out and when, shown only to the browser that held the session.

**For administrators:**

* **Users > Sessions**: every active session on the site, with search by user or IP address, a role filter, sorting and paging. Sign out one session, or sign a user out everywhere, one at a time or in bulk.
* A **Sessions** column on the Users list, with each user's count linked to their sessions.
* **Session limit per role**: how many sessions a user may have at once. A login over the limit signs out the oldest session, or is refused with a clear message, your choice.
* **Idle timeout per role**: sessions left unused for a number of minutes are signed out.
* **Session length per role**, with and without "Remember Me".
* **New device email** (off by default): users get an email when they sign in from a browser and network not seen on their account in the last 30 days.
* **Shared login detection**: when the sessions of an account look like more than one person, such as two computers on different networks at the same time, the account gets a "Possibly shared" badge with the reasons. Per role you choose whether that also emails the user and the site administrator, or keeps only the newest session. A login is never refused on this guess.
* **This wasn't me**: the new device email has a link for the owner of the account. Opening it changes nothing. Pressing the button on the page it opens signs the device out, makes the password stop working, emails a reset link and tells the site administrator. The link works once, for 7 days.
* **Sign everyone out**, for incidents: keep yourself signed in, leave out roles, optionally make every password stop working. You type a confirmation, and large sites are handled in batches.
* **Application passwords too**: signing someone out does not stop an application password, so "This wasn't me" and "Sign everyone out" revoke them as well. The incident form shows how many will go first.
* **Let me in by ending the other device**: with "Refuse the new login", a refused person sees their other devices after the password (and Two Factor) was accepted, ends one, and is signed in without typing the password again.
* **Shortened IP addresses**, if you would rather not store the full address.
* A **Site Health** check that warns when administrator sessions never time out.

"Last active" is saved inside each session, at most once every 5 minutes per session, so it costs almost nothing.

Free and open source under the GPL. No pro version, no upsells, no ads in wp-admin, no tracking. And it stays that way.

= Privacy =

Sessions hold an IP address and a browser string. The plugin takes part in Tools > Export Personal Data (it lists a user's sessions) and Tools > Erase Personal Data (it lists sessions, sign-in history and reports, and signs the user out everywhere and forgets them), adds suggested text to Settings > Privacy, and can store shortened IP addresses. The new device email keeps only a scrambled fingerprint of each browser and network, never the address, and forgets it after 30 days. Shared login detection uses only what the sessions already hold and calls no outside service. Sign-in history keeps the device, the IP address as stored, the times and how each session ended for 90 days by default (you can change it, or set 0 to keep none). A "This wasn't me" report keeps the device, address and time for administrators, until the user's data is erased or newer reports push it out of the latest 50. The log of "Sign everyone out" keeps the time, which administrator did it and the counts, not who was signed out, for the latest 20 runs. A session's own record, with its IP address and browser string, is deleted when the session ends. Nothing is sent to any outside service, and the plugin loads no files from outside your site. Deleting the plugin removes its settings, history, reports and flags. When an account looks shared, a copy of the sessions behind it is kept with the reasons for 7 days after the last sign, and the export and erase tools cover it.

= WP-CLI =

* `wp axle-sessions list [--user=<user>]` lists active sessions with their handles.
* `wp axle-sessions revoke <handle>` signs out one session.
* `wp axle-sessions sign-out <user>` signs a user out everywhere.
* `wp axle-sessions prune` removes expired sessions and signs out idle ones.
* `wp axle-sessions history <user>` shows when an account signed in and how each session ended.
* `wp axle-sessions reports` lists "This wasn't me" reports.
* `wp axle-sessions sign-out-everyone` signs every user out, with exclusions.
* `wp axle-sessions shared` lists accounts that look shared, with the reasons.
* `wp axle-sessions policies` and `set-policy <role>` read and change the per-role policies.
* `wp axle-sessions settings` reads and changes the other settings.

= For developers =

Every limit and default has a filter, and every sign out fires an action. The full list is in [the reference](https://github.com/aryanjasala/axle-sessions/blob/main/docs/REFERENCE.md).

== Frequently Asked Questions ==

= Does signing out a session log that browser out right away? =

On its next request. The session is deleted at once, so the next page it loads, or the next background check from an open admin tab, lands on the login screen.

= What counts as activity for the idle timeout? =

Any page or REST request made while signed in. The Heartbeat checks an open admin tab makes every minute do not count, so a forgotten tab still times out.

= What happens to people already signed in when I turn on a limit? =

Nothing until they sign in again. The limit is applied at login. An idle timeout applies to existing sessions from their next request.

= Will "Refuse the new login" lock out someone who lost their phone? =

No. After they enter the right password (and the Two Factor code, if they use it), they see their other devices and can sign one out to get in. This is offered to accounts that use Two Factor, and to accounts that cannot manage options or promote users. An administrator without Two Factor is never offered it, so a stolen password alone cannot push out their session. The list works once, in that browser, for 10 minutes. You can turn this off in the Policies tab. Then a refusal is final until the old session ends or an administrator signs it out. Signing out the oldest session stays the default.

= Does "This wasn't me" stop an API key someone made? =

Yes. It revokes every application password on the account, and so does "Sign everyone out" (except yours, so you can finish the job). A person can make new ones after signing in again.

= Does it work with social login and two-factor plugins? =

Yes, for plugins that sign people in through WordPress's own login functions. A plugin that sets the login cookie itself cannot be refused before the session exists, so in refuse mode that session is ended right after it starts.

= How does it decide that an account looks shared? =

From the sessions it already has: the network of each IP address, the browser and device, and when each session was last used. By default it flags more than two networks at the same time, two computers (or two phones, or two tablets) on different networks at the same time, or more than four different devices in a day. A laptop on Wi-Fi and a phone on mobile data are one person, so they are not flagged. Developers can change every limit with the `axle_sessions_shared_login_rules` filter, and add countries from their own lookup.

= Will it lock out people who share an account on purpose? =

No. By default it only shows a badge. Signing out every session but the newest is a choice per role, and a login is never refused. For accounts meant to be shared, such as a kiosk, set the role to "Do not check" or use the `axle_sessions_shared_login_exempt` filter.

= Is the "This wasn't me" link safe? =

The link holds a random token, and only a hash of it is stored. It is tied to one account and one session, works once, and expires after 7 days. Opening it only shows a page, so mail scanners that follow links do nothing. A wrong or used link gets the same page, and many wrong guesses from one address are slowed down. You can turn it off in the settings.

= Why does "Sign everyone out" not email people? =

An incident is a bad time for a mass email. People who are signed out see a message on the login screen. If you also require new passwords, they use "Lost your password?". Your own password is left alone so you can still sign in.

= Does it work on multisite? =

Yes. Users and their sessions are shared by the network, so the all-sessions screen and the settings live in Network Admin, and only network administrators change the settings. Device memory, sign-in history and reports are kept per user for the whole network. The hourly clean-up runs from the main site. Policies follow the roles a user has on the site they are using. People who may not edit other users see only their own sessions, on their profile.

= My host or another plugin stores sessions in Redis. Will this work? =

No. The plugin reads sessions from user meta, where WordPress keeps them by default. When another plugin moves them, the screens and Site Health say so.

== Screenshots ==

1. Profile > Your sessions, with the current device marked.
2. Users > Sessions with every signed-in device on the site.
3. Users > Sessions > Policies with policies per role.
4. A login refused over the session limit, with the other devices to sign out.
5. The "This wasn't me" page, listing the application passwords it will revoke.
6. Sign everyone out, with the application passwords to revoke.

== Changelog ==

= 1.0.1 =
* Fix: longer prefixes, nonce checks, and no remote actions through abilities

= 1.0.0 =
* First release.
