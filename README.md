Beam me up to Internet Archive (plugin for Omeka)
=================================================


This is but one plugin of two in active developement:
* Beam me up to Internet Archive
* Beam me up to SoundCloud

Pop Up Archive
------------

Omeka is an open-source framework designed for organizations such as libraries,
museums, and radio producers to archive their digital content. Written in PHP,
it uses the MySQL relational database management system and the Apache
webserver. Because it does not require root access to install, one can use Omeka
on server space provided by popular vendors such as Dreamhost. The considerable
expertise needed for deployment on a server with root access, such as those
provided by Amazon Web Services’ Elastic Cloud Computing, is not required.

The hottest Silicon Valley start-ups have trouble finding capable
systems engineers to manage their servers. Not-for-profits do not have the
resources to attract such talent. They need Omeka.

In addition to its ease of deployment, Omeka has several other advantages. It
has a paid staff out of George Mason University that develop and maintain its
core. Like any good open source project, it also has a large and active
community of volunteer developers. Omeka provides the architecture to enable
such developers (including the Pop Up Archive) to expand Omeka’s
functionality through plugins.

From an archival standpoint, the server that hosts Omeka and its data is typically 
rented space in the cloud. A change in credit card number can delete this data. 
From an outreach standpoint, making the front-end to an Omeka-powered website requires resources, 
and these sites are often lightly trafficked.

The Pop Up Project addresses these issues by getting the content in an
Omeka database outside the user's server. For posterity, this data is sent to the Internet
Archive, whose job is to store digital media in perpetuity. For accessibility,
the data is sent to SoundCloud, an audio repository with a superb interface and
skyrocketing popularity.

To do so, two plugins have been developed: one for the Internet Archive and the
other for SoundCloud. Omeka already has the code necessary
to get data from a local machine (e.g. a radio producer’s laptop) to its
database. The Pop Up Archive plugins provide the cURL code to get the data from
its database to more accessible, better maintained third parties.


Technical Components
--------------------

Passing the data to these third parties is easier said than done. Below is a
synopsis of the technical components that Pop Up Archive has used to do so.

### cURL
Both plugins are, at their core, cURL scripts. cURL is an open-source
command-line utility that runs HTTP calls that, in this case, is implemented
through a PHP object. The Internet Archive plugin sends HTTP PUT requests to an
API that mimics the popular S3 service of Amazon Web Services. The Internet
Archive’s S3-like API must create a bucket after the first file is received
before subsequent files are sent. This requires that a metadata object goes out
like a scout, and then creates the bucket, while a while loop runs, after which
other files are sent in a multithreaded process. This currently presents UI
problems, as an Omeka user may stare at a white screen for upwards of two
minutes before the process completes. This issue will be addressed in the future
by getting the on a background thread.

The SoundCloud plugin has no such problems with bucket creation, and its scripts
can simply be sent in a multithreaded process. This process should still be
daemonized for the sake of better user interaction.


### Authentication
The Internet Archive uses public-key authentication. On installation, the
Pop-up Radio plugin prompts the user to enter the Internet Archive-provided
public key, and then saves this key in Omeka’s persistent memory. This public
key is then passed to the Internet Archive in the headers of the HTTP PUT.

In the case of SoundCloud, OAuth authentication is used. Users are prompted to
log into SoundCloud on installation, and then soundcloud sends a token back to
the plugin. This token is saved in Omeka’s persistent memory and sent in the
header of an HTTP POST request to authenticate tracks that are posted to
SoundCloud.

### Omeka Admin Integration
All of the above processes are exclusively run in Omeka’s after_save_item hook,
which is implemented after an Omeka item is saved. An item’s admin screen has a
tab for each plugin, and this tab enables users to check boxes that specify
whether they would like the plugin to work for the item in question.

This presents a convenient way to get new items to the third parties, but means
that old items have to be resaved in order for the plugins to work for them.
There is also no way for an Omeka user to see which files have gotten to the
Internet Archive and SoundCloud without going to these sites. In the future,
Omeka’s database should keeps track of what uploads have occur. Through Omeka’s
Model-View-Controller framework, this data can be clearly displayed on one
table. Futhermore, the cURL script, both for individual and groups of
non-uploaded files, can be run from this same page.


Installation
------------

Uncompress files and rename plugin folder "BeamMeUpToSoundCloud".

Then install it like any other Omeka plugin and follow the config instructions.

Note that you need to apply for a free account on [SoundCloud][2].

This plugin uses [php-soundcloud][3], an api designed to connect to SoundCloud.

Warning
-------

Use it at your own risk.

It's always recommended to backup your database so you can roll back if needed.


Troubleshooting
---------------

See online issues on [GitHub][4].


License
-------

This plugin is published with a double licence:

### [CeCILL][5]

In consideration of access to the source code and the rights to copy,
modify and redistribute granted by the license, users are provided only
with a limited warranty and the software's author, the holder of the
economic rights, and the successive licensors only have limited liability.

In this respect, the risks associated with loading, using, modifying
and/or developing or reproducing the software by the user are brought to
the user's attention, given its Free Software status, which may make it
complicated to use, with the result that its use is reserved for
developers and experienced professionals having in-depth computer
knowledge. Users are therefore encouraged to load and test the
suitability of the software as regards their requirements in conditions
enabling the security of their systems and/or data to be ensured and,
more generally, to use and operate it in the same conditions of
security. This Agreement may be freely reproduced and published,
provided it is not altered, and that no provisions are either added or
removed herefrom.

### [GNU/GPL][6]

This program is free software; you can redistribute it and/or modify it under
the terms of the GNU General Public License as published by the Free Software
Foundation; either version 3 of the License, or (at your option) any later
version.

This program is distributed in the hope that it will be useful, but WITHOUT
ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS
FOR A PARTICULAR PURPOSE. See the GNU General Public License for more
details.

You should have received a copy of the GNU General Public License along with
this program; if not, write to the Free Software Foundation, Inc.,
51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.


### The [php-soundcloud][3] library is published under [MIT licence][7].

Contact
-------

Current maintainers:

* Daniel Berthereau (see [Daniel-KM][6])

This plugin has been built and updated for [Pop Up Archive][7].


Copyright
---------

* Copyright Dave Lester for Pop Up Archive, 2012 (see [Dave Lester][8])
* Copyright Daniel Vizzini for Pop Up Archive, 2012 (see [DVizzini][9])
* Copyright Daniel Berthereau for Pop Up Archive, 2012-2013


[1]: https://omeka.org "Omeka.org"
[2]: https://archive.org "Internet Archive"
[3]: https://github.com/popuparchive/BeamMeUpToInternetArchive/Issues "GitHub BeamMeUpToInternetArchive"
[4]: https://www.cecill.info/licences/Licence_CeCILL_V2-en.html "CeCILL"
[5]: https://www.gnu.org/licenses/gpl-3.0.html "GNU/GPL"
[6]: https://github.com/Daniel-KM "Daniel Berthereau"
[7]: http://popuparchive.org/ "Pop Up Archive"
[8]: https://github.com/davelester "Dave Lester"
[9]: https://github.com/dvizzini "Daniel Vizzini"
