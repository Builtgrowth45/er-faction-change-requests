# Faction Change Requests: XenForo 2 add-on for the Empire Rising forums

Replaces the "Faction Forum Access" thread (index.php?threads/faction-forum-access.442) with a
form plus an approval queue.

**Status: written but not yet installed on any XenForo.** The PHP parses cleanly; see "Checks done" below.
Install it on a test board, or on the live one while it is closed, before members use it.

## What players and approvers see

- **Player:** `index.php?faction-requests/` shows their current in-game name, faction, rank and
  department, and a form: in-game name, faction (dropdown), rank R0 to R7, department or cell,
  and an optional note. One pending request at a time. They can cancel a request and see their history.
- **Approver:** `index.php?faction-requests/queue` lists every pending request as old → new.
  Approve or Reject, with an optional reason. Recent decisions show who approved what.
  - Staff with *Approve any* see every request.
  - Faction leaders with *Approve own faction* only see requests to join their own faction,
    judged by their own faction profile field, and never their own request.
- **On approval, in one transaction:**
  - The in-game name, faction and rank profile fields are written, plus the department field if one
    is set. The rank is written as the faction's real title: R4 in the LED becomes Detective, R7 in
    the GOM becomes Consul.
  - The member is removed from every faction user group listed in the options and added to the
    new one. That swaps their faction banner and their faction forum access. Other groups such as
    Dev Tester and Faction Leader are left alone.
  - The old values and groups are stored on the request for audit or a manual revert.
  - The member gets an alert. A rejection also alerts them, with the reason.
- **Cooldown:** a member can't send a new request until N days after their last approved change
  (default 7, and 0 turns it off).

## Install

1. Upload `upload/` to the forum root, so the code lands in `src/addons/EmpireRising/FactionChange/`.
   (`EmpireRising-FactionChange-1.0.0.zip` holds the same files, including `upload/`.)
2. In the admin control panel, go to Add-ons and install **Faction Change Requests**.
   This creates the table `xf_er_faction_request` and gives *Request a faction change* to every
   group that can start threads.

## Configure

All settings are under Setup → Options → Faction change requests.

| Option | Set it to |
|---|---|
| Factions | One line per faction: `key\|label\|user group id\|rank titles`. The **key** must equal the choice key of the faction profile field. The live forum's markup confirms `led`, `fdc`, `gom`, `motb` and `vortex`; `bos`, `cmg` and `eurocore` are guesses. Put each faction's **user group id** in the third column; the id is in the URL when you edit the group. Rank titles come from the game's own text (CRes.dll 1.5.9.9) and are pre-filled. |
| In-game name / Faction / Rank / Department field id | The field ids from Users → Custom user fields. The defaults are guesses (`ign`, `faction`, `rank`, and none for department). |
| Rank field format | `{title}` by default. Use `{title} · {department}` to show both on the player box's single rank line. |
| Days between faction changes | 7 by default. |

Then set permissions under Groups & permissions → User group permissions → *Faction change requests*:
- **Faction Leader** group: *Approve requests to join own faction* = Yes.
- **Administrative / Moderating** groups: *Approve any faction change request* = Yes.

Finally, add a link for members. In Setup → Public navigation, add a link under Forums to
`{{ link('faction-requests') }}`. Or link it from the old thread and lock that thread.

To show the department on the player box, add a field (for example `department`), put its id in the
option, and add this to the player box template next to the rank row:

```html
<xf:if is="$user.Profile.custom_fields.department">
	<div class="row dept">{$user.Profile.custom_fields.department}</div>
</xf:if>
```

## Test before members use it

1. Make a test member in, say, LED, with a known faction group.
2. As that member, request MotB, R5, department "MADE".
3. As an MotB leader (with *own faction* permission), confirm the request shows in the queue. As an
   LED leader, confirm it does not.
4. Approve it. Then check the member's player box: name, MotB logo, rank title "Freelancer". Check
   that the banner changed, that the MotB forums are now visible and the LED ones are not, and that
   the alert arrived.

If a profile field can't be written (wrong id, or a value that isn't one of the field's choices),
the approval stops with a message naming the field, and **nothing** is changed.

## Files

| Path | What |
|---|---|
| `upload/src/addons/EmpireRising/FactionChange/` | The add-on: `Setup.php`, `Entity/Request.php`, `Repository/FactionRequest.php`, `Service/Request/Resolve.php`, `Pub/Controller/FactionRequest.php`, `_data/*.xml`. |
| `templates_src/` | Readable template sources; `build_data.py` packs them into `_data/templates.xml`. |
| `build_data.py` | Rebuilds `templates.xml` and `phrases.xml` and the zip. Edit the templates or phrases here, then run it. |

## Checks done

- PHP syntax: every file parses with the npm `php-parser` 3.1.5 (PHP 7 grammar). That parser rejected
  a deliberately broken file, so the check is real. A `php -l` run wasn't possible because Docker
  Desktop wouldn't start from the session.
- `_data/*.xml` parse as XML.
- **Not done:** an install on a real XenForo, because there's no licence or copy here. The parts most
  worth watching on the first install are the XenForo template syntax, the profile field write, and
  the custom alert templates (`alert_user_er_faction_*`).
