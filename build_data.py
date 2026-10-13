"""Writes _data/templates.xml and _data/phrases.xml from templates_src/ and the PHRASES table,
then zips upload/ into a release archive. Usage: python build_data.py"""
import os, zipfile
from xml.sax.saxutils import quoteattr

HERE = os.path.dirname(os.path.abspath(__file__))
ADDON = os.path.join(HERE, "upload", "src", "addons", "EmpireRising", "FactionChange")
DATA = os.path.join(ADDON, "_data")
VERSION_ID, VERSION_STRING = 1000010, "1.0.0"

PHRASES = {
    "permission_interface.erFaction": "Faction change requests",
    "permission.erFaction_request": "Request a faction change",
    "permission.erFaction_approveOwn": "Approve requests to join own faction (faction leaders)",
    "permission.erFaction_approveAny": "Approve any faction change request (staff)",
    "option_group.erFaction": "Faction change requests",
    "option_group_description.erFaction": "Factions, profile fields and rules for the faction change form.",
    "option.erFactionMap": "Factions",
    "option_explain.erFactionMap": "One faction per line: key|label|faction user group id|rank titles R0 to R7, comma separated. The key must match the choice key of the faction profile field. On approval the member is removed from every faction group listed here and added to the new one. Use 0 to leave groups alone for a faction.",
    "option.erFactionFieldIgn": "In-game name field id",
    "option_explain.erFactionFieldIgn": "Field id of the custom user field holding the in-game name. Leave blank to not write it.",
    "option.erFactionFieldFaction": "Faction field id",
    "option_explain.erFactionFieldFaction": "Field id of the custom user field holding the faction key (led, fdc, gom, motb, vortex...). Faction leaders are matched to requests through this field.",
    "option.erFactionFieldRank": "Rank field id",
    "option_explain.erFactionFieldRank": "Field id of the custom user field shown as the rank line of the player box. Leave blank to not write it.",
    "option.erFactionFieldDepartment": "Department field id",
    "option_explain.erFactionFieldDepartment": "Field id of a custom user field for the department or cell. Leave blank if there isn't one; the department can still go into the rank line through the format below.",
    "option.erFactionRankFormat": "Rank field format",
    "option_explain.erFactionRankFormat": "What is written to the rank field. Placeholders: {title} (e.g. Detective), {rank} (0-7), {department}, {faction}. Examples: {title} or {title} &middot; {department}.",
    "option.erFactionCooldownDays": "Days between faction changes",
    "option_explain.erFactionCooldownDays": "A member can't send a new request until this many days after their last approved change. 0 turns it off.",
}

def cdata(text):
    return "<![CDATA[" + text.replace("]]>", "]]]]><![CDATA[>") + "]]>"

def write_templates():
    src = os.path.join(HERE, "templates_src")
    parts = ['<?xml version="1.0" encoding="utf-8"?>', "<templates>"]
    for name in sorted(os.listdir(src)):
        title, ext = os.path.splitext(name)
        assert ext == ".html", name
        body = open(os.path.join(src, name), encoding="utf-8").read().strip() + "\n"
        parts.append('  <template type="public" title=%s version_id="%d" version_string="%s">%s</template>'
                     % (quoteattr(title), VERSION_ID, VERSION_STRING, cdata(body)))
    parts.append("</templates>")
    open(os.path.join(DATA, "templates.xml"), "w", encoding="utf-8", newline="\n").write("\n".join(parts) + "\n")
    return len(parts) - 3

def write_phrases():
    parts = ['<?xml version="1.0" encoding="utf-8"?>', "<phrases>"]
    for title in sorted(PHRASES):
        parts.append('  <phrase title=%s version_id="%d" version_string="%s">%s</phrase>'
                     % (quoteattr(title), VERSION_ID, VERSION_STRING, cdata(PHRASES[title])))
    parts.append("</phrases>")
    open(os.path.join(DATA, "phrases.xml"), "w", encoding="utf-8", newline="\n").write("\n".join(parts) + "\n")
    return len(PHRASES)

def write_zip():
    out = os.path.join(HERE, "EmpireRising-FactionChange-%s.zip" % VERSION_STRING)
    upload = os.path.join(HERE, "upload")
    with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as z:
        for root, _, files in os.walk(upload):
            for f in files:
                full = os.path.join(root, f)
                z.write(full, os.path.relpath(full, HERE).replace(os.sep, "/"))
        z.write(os.path.join(HERE, "README.md"), "README.md")
    return out

if __name__ == "__main__":
    print("templates", write_templates())
    print("phrases", write_phrases())
    print("zip", write_zip())
