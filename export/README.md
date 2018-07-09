# ZetaBoards Exporter

Program to export a ZetaBoards board as a sqlite3 database file.

----

This program scrapes a ZetaBoards forum, and creates a sqlite3 database file with all the members, topics, posts, polls and emojis from that forum.

To run this program, you will need Python 2.7 installed, with modules requests beautifulsoup4 html5lib demjson python-dateutil installed. To install them, use the command:

`pip install requests beautifulsoup4 html5lib demjson python-dateutil`

Then download the `dump.py` file. To setup the exporter, some settings in `dump.py` need to be modified for your board. Open it up in your favourite text editor, and change lines 33 to 38 to match your board URL, admin panel URL, tapatalk URL, and authentication cookies.

The cookie can be found by logging in to the forum with a web browser (Chrome or Firefox) and then copying the cookie value from the Developer Inspector.

----

Before running the exporter, first create a new directory called `emojis` in the same directory that you downloaded `dump.py` into.

To run the expoter, the command line command `python dump.py` should be used. This will create the `database.db` file with all the data from your forum, and will also download all emojis into the `emojis/` directory you created previously.

----

If you have any issues with this program, join [this discord](https://discord.gg/A5DmErU), and ask @tapedrive for help.
