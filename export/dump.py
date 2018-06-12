# ZetaDump - export a ZetaBoards forum as a sqlite3 database
# Copyright (C) 2018  tapedrive
# 
# This program is free software; you can redistribute it and/or
# modify it under the terms of the GNU General Public License
# as published by the Free Software Foundation; either version 2
# of the License, or (at your option) any later version.
# 
# This program is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
# GNU General Public License for more details.
# 
# You should have received a copy of the GNU General Public License
# along with this program; if not, write to the Free Software
# Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.


#!/usr/bin/python

import sqlite3
import requests
import demjson
import re
import datetime
import sys
import traceback
import mimetypes
from bs4 import BeautifulSoup
from urlparse import urlparse


BOARD_URL = "http://w11.zetaboards.com/dontcryjennifer/" # enter your board url WITHOUT the index/, and with a trailing /
ADMIN_URL = "http://w11.zetaboards.com/dontcryjennifer/admin/" # url of the ACP, with a trailing /
TAPATALK_URL = "https://www.tapatalk.com/groups/dontcryjennifer/"

COOKIE = {"1810868sess": "176a0d14f2b8f41c003826699"} # cookie of user that has either Administrator access, or Admin Assistant access
COOKIE_ADMIN = {"1810868acp":"564f9b8fbd647a543826479"} # cookie of user when they are logged in to the ACP - this has a short expiry rate
COOKIE_POLL = COOKIE


# untested. probably works, but probably best to leave alone
DOWNLOAD_AVATARS = False
DOWNLOAD_PHOTOS = False
USE_TAPATALK_JOIN_DATE = False


# for nice colored printing
class bcolors:
    HEADER = '\033[95m'
    OKBLUE = '\033[94m'
    OKGREEN = '\033[92m'
    WARNING = '\033[93m'
    FAIL = '\033[91m'
    ENDC = '\033[0m'
    BOLD = '\033[1m'
    UNDERLINE = '\033[4m'

def getZetaUrl(url):
    parsed = urlparse(url)
    path = parsed.path
    parts = path.split('/')
    if (len(parts) >= 3):
        type = parts[1]
        id = parts[2]
        return [type, id]
    elif (len(parts) >= 2):
        type = parts[1]
        return [type, False]
    return [False, False]

def setupDatabase():
    print "(1/5) ########## SETTING UP SQL  ##########"
    # create board, forum, topic, post, poll, option and member tables
    #cursor.execute('''CREATE TABLE board (id integer, server integer, url text, name text)''')
    cursor.execute('''CREATE TABLE forum (id integer PRIMARY KEY, parent integer, name text, description text, `order` integer)''')
    cursor.execute('''CREATE TABLE topic (id integer PRIMARY KEY, forum integer, poll integer, name text, description text, tags text, views integer)''')
    cursor.execute('''CREATE TABLE post (id integer PRIMARY KEY, topic integer, member integer, date integer, bbcode text, html text)''')
    cursor.execute('''CREATE INDEX member_idx ON post (member)''')
    cursor.execute('''CREATE INDEX topic_idx ON post (topic)''')
    cursor.execute('''CREATE TABLE poll (id integer PRIMARY KEY, question text, options integer)''')
    cursor.execute('''CREATE TABLE option (id integer, poll integer, option text, votes integer, PRIMARY KEY (id, poll))''')
    cursor.execute('''CREATE TABLE member
    (id integer PRIMARY KEY, name text, password text, email text, birthday integer, number integer, joined integer, ip text, `group` text, title text, warning integer, pms integer, ipbans integer, photoremote text, photolocal text, avatarremote text, avatarlocal text, interests text, signaturebb text, signaturehtml text, location text, aol text, yahoo text, msn text, homepage text, lastactive integer, hourdifference real, numposts integer)''')
    cursor.execute('''CREATE TABLE emoji (id integer PRIMARY KEY, code text, remote text, local text)''')
    # save the changes to the database
    conn.commit()


def scrapeBoard():
    # get board main page
    r = requests.get(BOARD_URL, cookies=COOKIE)
    if r.status_code != 200:
        print bcolors.FAIL + "Non-200 status code scraping " + BOARD_URL + bcolors.ENDC
        sys.exit(0)

    # parse webpage
    soup = BeautifulSoup(r.text, "html.parser")

    # get javascript data - bit of hackery here
    scripts = soup.find_all("script")
    for script in scripts:
        if "zb.stat" in script.text:
            zetadata = script.text
            break
    zetadata = zetadata[(zetadata.find("{")):(zetadata.find("}")+1)]
    zetadata = demjson.decode(zetadata)

    # get required data
    id = zetadata["bpath"]
    server = zetadata["server"]
    url = zetadata["url"]
    name = soup.title.string

    # insert it into the database
    values = (id, server, url, name)
    #cursor.execute('INSERT INTO board VALUES (?,?,?,?)', values)
    #conn.commit()


def scrapeForums():
    print "(2/5) ########## SCRAPING FORUMS ##########"
    ids = getForumIDs()
    insertCount = 1

    for id in ids:
        url = BOARD_URL + "forum/" + id + "/"
        r = requests.get(url, cookies=COOKIE)
        if r.status_code != 200:
            print bcolors.FAIL + "Non-200 status code scraping " + url + bcolors.ENDC
            sys.exit(0)

        # parse webpage
        soup = BeautifulSoup(r.text, "html.parser")

        # get name of this forum
        name = soup.title.string

        # get parent of this forum (if any)
        parent = soup.find("ul", id="nav").find_all("a", href=True)[-1]
        zetaUrl = getZetaUrl(parent['href'])
        type = zetaUrl[0]
        parentID = zetaUrl[1]

        if type == "index":
            parentID = 0
        elif type == False:
            print "Error getting forum parent: " + parent

        description = ""

        # insert it into the database
        values = (id, parentID, name, description, insertCount)
        cursor.execute('INSERT INTO forum VALUES (?,?,?,?,?)', values)
        conn.commit()

        insertCount = insertCount + 1


def getForumIDs():
    forumIDs = findAllForums(BOARD_URL)

    finished = False
    while finished == False:
        # get current length
        currentUrls = len(forumIDs)

        # now check all the forums in the list, to see if there are subforums
        for id in forumIDs:
            url = BOARD_URL + "forum/" + id + "/"

            newIDs = findAllForums(url)
            for newID in newIDs:
                if newID not in forumIDs:
                    forumIDs.append(newIDs)


        # are we finished?
        if currentUrls == len(forumIDs):
            finished = True

    return forumIDs


def findAllForums(url):
    forumIDs = []

    # get board main page
    r = requests.get(url, cookies=COOKIE)
    if r.status_code != 200:
        print bcolors.FAIL + "Non-200 status code scraping " + url + bcolors.ENDC
        sys.exit(0)

    # parse webpage
    soup = BeautifulSoup(r.text, "html.parser")

    # find all urls
    urls = soup.find_all("a", href=True)

    # check if they are forum urls
    for url in urls:
        zetaUrl = getZetaUrl(url['href'])
        type = zetaUrl[0]
        id = zetaUrl[1]

        if type == False or id == False:
            continue

        if type == "forum" and id not in forumIDs:
            forumIDs.append(id)

    return forumIDs


def getMaxPage(soup):
    pagesContainer = soup.find("ul", {"class": "cat-pages"})

    # special case if there are no pages
    if pagesContainer == None:
        return 1

    pages = pagesContainer.find_all("a", href=True)
    maxPage = pages[-1]
    maxPage = maxPage.text
    return maxPage


def scrapeTopics():
    print "(3/5) ########## SCRAPING TOPICS ##########"
    forums = cursor.execute("SELECT id FROM forum").fetchall()
    for forum in forums:
        forumID = forum[0]
        print "Scraping topic from forum " + str(forumID)

        # get forum page
        url = BOARD_URL + "forum/" + str(forumID) + "/"
        r = requests.get(url, cookies=COOKIE)
        if r.status_code != 200:
            print bcolors.FAIL + "Non-200 status code scraping " + url + bcolors.ENDC
            sys.exit(0)

        # parse webpage
        soup = BeautifulSoup(r.text, "html.parser")

        # get total pages
        maxPage = int(getMaxPage(soup))

        # somewhere to hold the topic IDs
        topicIDs = []

        # loop through all pages
        for page in range(1, maxPage+1):

            if page != 1:
                # get forum page
                url = BOARD_URL + "forum/" + str(forumID) + "/" + str(page) + "/"
                r = requests.get(url, cookies=COOKIE)
                if r.status_code != 200:
                    print bcolors.FAIL + "Non-200 status code scraping " + url + bcolors.ENDC
                    sys.exit(0)

                # parse webpage
                soup = BeautifulSoup(r.text, "html.parser")

            # get list of topics
            topicContainer = soup.find("form", {"id": "inlinetopic"})

            if topicContainer == None:
                print bcolors.FAIL + "no topic container found: " + url + bcolors.ENDC
                continue

            #topics = topicContainer.find_all("a", href=True)
            titles = topicContainer.find_all("td", {"class": "c_cat-title"})

            for title in titles:
                topic = title.find_all("a", href=True)[-1]

                zetaUrl = getZetaUrl(topic['href'])
                type = zetaUrl[0]
                id = zetaUrl[1]

                if type == False or id == False:
                    continue

                if type == "topic" and id not in topicIDs:
                    topicIDs.append(id)

                    topicURL = BOARD_URL + "topic/" + id + "/1/"

                    # id = id
                    # forum = forumID
                    name = topic.text.strip()

                    if len(name) == 0 or name == "":
                        print bcolors.FAIL + "Empty name for topic " + topic["href"] + bcolors.ENDC
                        print topic
                        continue

                    description = topic.parent.find("div", {"class": "description"})
                    if description == None:
                        description = None
                    else:
                        description = description.text.strip()
                    tags = None

                    views = topic.parent.parent.parent
                    if views.name == "td":
                        views = views.parent
                    views = views.find("td", {"class": "c_cat-views"})
                    if views == None:
                        print bcolors.WARNING + "No views found :: " + topic.parent.parent.parent + bcolors.ENDC
                        continue # skip moved topics - we'll pick them up in the correct location
                    views = views.contents[0].strip()
                    views = int(views.replace(",",""))


                    # get polls - placeholder for poll ID
                    pollID = None

                    # load topic page as cookie user
                    url = BOARD_URL + "topic/" + str(id) + "/1/"
                    r = requests.get(url, cookies=COOKIE_POLL)
                    if r.status_code != 200:
                        print bcolors.FAIL + "Non-200 status code scraping " + url + bcolors.ENDC
                        sys.exit(0)

                    # parse topic page
                    soup = BeautifulSoup(r.text, "html.parser")

                    # get poll (if there is one)
                    poll = soup.find("table", {"class":"poll"})
                    if poll != None:
                        question = poll.find("thead").find("th").text.strip()
                        options = []
                        votes = []

                        # get number of options that can be chosen
                        numChosen = 1
                        selectUpTo = soup.find(string="Select up to ")
                        if selectUpTo != None:
                            choices = selectUpTo.find_next("strong")
                            if choices == None:
                                break
                            numChosenText = choices.text.replace(" choices", "")
                            numChosen = int(numChosenText)

                        # now submit an empty answer so we can get the results
                        form = soup.find("form", id=re.compile('^poll'))
                        pollID = form.get("id").replace("poll", "")

                        voteForNoneButton = form.find("a", {"class":"btn_fake"})
                        if voteForNoneButton == None:
                            print bcolors.FAIL + "Error scraping poll, poll doesn't have 'Vote for none' button, likely previously submitted :: " + url + bcolors.ENDC
                        else:

                            url = voteForNoneButton["href"]
                            xc = form.find("input", {"name":"xc"}).get("value")
                            headers = {
                                "Content-Type": "application/x-www-form-urlencoded"
                            }
                            data = {
                                "xc": xc
                            }

                            # load poll results as cookie user
                            r = requests.post(url, data=data, headers=headers, cookies=COOKIE_POLL)
                            if r.status_code != 200:
                                print bcolors.FAIL + "Non-200 status code scraping " + url + bcolors.ENDC
                                sys.exit(0)

                            # check for errors (probably indicating we can't post here)
                            errorBox = soup.find("table", id="error_box")
                            if errorBox != None:
                                print bcolors.FAIL + "Error scraping poll, likely do not have permission to reply to polls here :: " + url + bcolors.ENDC
                            else:

                                # parse poll results
                                soup = BeautifulSoup(r.text, "html.parser")

                                # get containers for options
                                poll = soup.find("table", {"class":"poll"})
                                optionContainers = poll.find_all("td", "c_poll-answer")
                                voteContainers = poll.find_all("td", "c_poll-votes")

                                # check the votes match the options
                                if (len(optionContainers) != len(voteContainers)):
                                    print bcolors.FAIL + "Poll options don't match poll votes :: " + url + bcolors.ENDC
                                    print optionContainers
                                    print voteContainers
                                    sys.exit(0)

                                # loop through options
                                for i in range(0, len(optionContainers)):
                                    option = optionContainers[i].text.strip()
                                    vote = voteContainers[i].find("strong").text.strip()
                                    options.append(option)
                                    votes.append(vote)

                                # insert poll into database
                                values = (pollID, question, numChosen)
                                cursor.execute('INSERT INTO poll VALUES (?,?,?)', values)

                                # insert options into database
                                for i in range(0, len(options)):
                                    values = ((i+1), pollID, options[i], votes[i])
                                    cursor.execute('INSERT INTO option VALUES (?,?,?,?)', values)


                    # insert it into the database
                    values = (id, forumID, pollID, name, description, tags, views)
                    cursor.execute('INSERT INTO topic VALUES (?,?,?,?,?,?,?)', values)
                    conn.commit()
                    print bcolors.OKGREEN + "Inserted topic ID: " + str(id) + bcolors.ENDC



def parseDate(zetaDate):
    zetaDate = zetaDate.lower().strip()

    if ("minute" in zetaDate or "minutes" in zetaDate) and ("ago" in zetaDate):
        # of the format X minute[s] ago
        minutesAgo = zetaDate.split(" ")[0]

        # special case - zeta uses "one" instead of "1"
        if minutesAgo == "one":
            minutesAgo = "1"

        # convert string number to int
        minutesAgo = int(minutesAgo)

        # get date of this time
        postDate = datetime.datetime.now() - datetime.timedelta(minutes=minutesAgo)

    elif "today" in zetaDate:
        day = datetime.datetime.now()
        postDate = date = datetime.datetime.strptime(zetaDate, "today, %I:%M %p")
        postDate = day.replace(hour=postDate.hour, minute=postDate.minute, second=postDate.second)

    elif "yesterday" in zetaDate:
        day = datetime.datetime.now()
        postDate = date = datetime.datetime.strptime(zetaDate, "yesterday, %I:%M %p")
        postDate = day.replace(hour=postDate.hour, minute=postDate.minute, second=postDate.second)

    else:
        postDate = datetime.datetime.strptime(zetaDate, "%b %d %Y, %I:%M %p")

    # convert postDate into epoch
    epoch = postDate.strftime('%s')

    return int(epoch)


def scrapePosts():
    print "(4/5) ########## SCRAPING POSTS  ##########"
    topics = cursor.execute("SELECT id FROM topic").fetchall()
    for topic in topics:
        topicID = topic[0]
        print "Scraping topic " + str(topicID)

        # get topic page
        url = BOARD_URL + "topic/" + str(topicID) + "/1/"
        r = requests.get(url, cookies=COOKIE)
        if r.status_code != 200:
            print bcolors.FAIL + "Non-200 status code scraping " + url + bcolors.ENDC
            sys.exit(0)

        # parse webpage
        soup = BeautifulSoup(r.text, "html.parser")

        # get total pages
        maxPage = int(getMaxPage(soup))

        # somewhere to hold the post IDs
        postIDs = []

        # loop through all pages
        for page in range(1, maxPage+1):

            if page != 1:
                # get topic page
                url = BOARD_URL + "topic/" + str(topicID) + "/" + str(page) + "/"
                r = requests.get(url, cookies=COOKIE)
                if r.status_code != 200:
                    print bcolors.FAIL + "Non-200 status code scraping " + url + bcolors.ENDC
                    sys.exit(0)

                # parse webpage
                soup = BeautifulSoup(r.text, "html.parser")

            # get list of posts
            postContainer = soup.find("table", id="topic_viewer")
            formContainer = postContainer.parent
            postContainer = formContainer.find_all("table", {"class":"topic"})[-1]

            # get post header and post body
            postHeaders = postContainer.find_all('tr', id=re.compile('^post-'))

            for postHeader in postHeaders:
                # get the post id
                postID = postHeader.get('id').replace("post-", "")

                if postID in postIDs:
                    continue

                postIDs.append(postID)

                # get the post date
                date = postHeader.find("td", {"class":"c_postinfo"}).find("span", {"class":"left"}).text.strip()
                date = parseDate(date)

                # get the poster ID
                memberLink = postHeader.find("td", {"class":"c_username"}).find("a", href=True)

                # check if this post is by a deleted user
                memberID = None
                if memberLink != None:
                    memberUrl = postHeader.find("td", {"class":"c_username"}).find("a", href=True)["href"]
                    zetaUrl = getZetaUrl(memberUrl)
                    memberID = zetaUrl[1]

                postBody = postHeader.find_next("tr")

                # get the post html
                postElement = postBody.find("td", {"class":"c_post"})
                html = postElement.decode_contents().strip()

                # get bbcode text (from reply with quote system)
                footIcons = postBody.find_next("td", {'class':'c_footicons'})
                links = footIcons.find_all("a", href=True)
                for link in links:
                    if "/post/?mode=2&type=2" in link["href"]:
                        # found the link to the quote page
                        r = requests.get(link["href"], cookies=COOKIE)
                        if r.status_code != 200:
                            print bcolors.FAIL + "Non-200 status code scraping " + link["href"] + bcolors.ENDC
                            sys.exit(0)

                        # parse webpage
                        soup = BeautifulSoup(r.text, "html.parser")

                        # get bbcode of the post we're quoting
                        bbcode = soup.find("textarea", id="txt_quote").contents[0]

                # now save this post in the database
                values = (postID, topicID, memberID, date, bbcode, html)
                try:
                    cursor.execute('INSERT INTO post VALUES (?,?,?,?,?,?)', values)
                except sqlite3.IntegrityError:
                    print "SQLITE3 INTEGRITY ERROR - inserting post into database\n"
                    print values
                    print ""
                    traceback.print_exc()
                conn.commit()
                print bcolors.OKGREEN + "Inserted post ID: " + str(postID) + bcolors.ENDC


def scrapeMembers():
    print "(5/5) ########## SCRAPING MEMBERS #########"

    sys.setrecursionlimit(100) # this will need to be adjusted depending on how many deleted users your board has (mine has 3, I think). Make sure it's BIGGER.

    # get admin member search page
    url = ADMIN_URL + "?menu=mem&c=4&do_search=1&name_order=startswith&search_email=@&pg=1"
    r = requests.get(url, cookies=COOKIE_ADMIN)
    if r.status_code != 200:
        print bcolors.FAIL + "Non-200 status code scraping " + url + bcolors.ENDC
        sys.exit(0)

    # parse webpage
    soup = BeautifulSoup(r.text, "html.parser")

    maxPage = int(getMaxPage(soup))

    # place to store user IDs
    userIDs = []

    #loop through pages
    for page in range(1, (maxPage+1)):

        if page != 1:
            # get admin member search page
            url = ADMIN_URL + "?menu=mem&c=4&do_search=1&name_order=startswith&search_email=@&pg=" + str(page)
            r = requests.get(url, cookies=COOKIE_ADMIN)
            if r.status_code != 200:
                print bcolors.FAIL + "Non-200 status code scraping " + url + bcolors.ENDC
                sys.exit(0)

            # parse webpage
            soup = BeautifulSoup(r.text, "html.parser")

        usersContainer = soup.find("table", id="membersearch")
        users = usersContainer.find_all("td", {"class":"ms_name"})

        for user in users:
            userUrl = user.find("a", {"class":"member"})["href"]
            print "processing " + userUrl
            zetaUrl = getZetaUrl(userUrl)
            type = zetaUrl[0]
            userID = zetaUrl[1]

            username = user.find("a", {"class":"member"}).text

            if not userID in userIDs:
                userIDs.append(userID)

                # scrape the edit user page
                url = ADMIN_URL + "?menu=mem&c=1&mid=" + str(userID)
                r = requests.get(url, cookies=COOKIE_ADMIN)
                if r.status_code != 200:
                    print bcolors.FAIL + "Non-200 status code scraping " + url + bcolors.ENDC
                    sys.exit(0)

                # parse webpage
                soup = BeautifulSoup(r.text, "html.parser")

                form = soup.find("div", id="main").find("form")

                ip = form.find("td", string="Registered with IP Address:").find_next("td").text

                email = form.find("input", {"name":"email"}).get("value")
                numPosts = int(form.find("input", {"name":"postcount"}).get("value").replace(",", ""))
                warning = int(form.find("input", {"name":"warned"}).get("value").replace(",",""))
                title = form.find("input", {"name":"mem_title"}).get("value")
                #group = form.find("select", {"name":"gid"}).find('option', selected=True).get("value") # doesn't work for Admins
                #permission =
                pms = form.find("input", {"type":"radio", "name":"ban_pm", "checked":"checked"}).get("value")
                ipbans = form.find("input", {"type":"radio", "name":"ban_pm", "checked":"checked"}).get("value")

                location = form.find("input", {"name":"loc"}).get("value")
                aol = form.find("input", {"name":"aim"}).get("value")
                yahoo = form.find("input", {"name":"yim"}).get("value")
                msn = form.find("input", {"name":"msn"}).get("value")
                homepage = form.find("input", {"name":"www"}).get("value")

                interestsArr = form.find("textarea", {"name":"interests"}).contents
                signaturebbcodeArr = form.find("textarea", {"name":"sig"}).contents

                photo = form.find("input", {"name":"photo"})
                if photo == None:
                    # likely to be an actual img element
                    photo = soup.find("td", {"class":"c_desc"}, string="Photo").find_next("img").get("src")
                else:
                    photo = photo.get("value")

                avatar = form.find("input", {"name":"av_url"})
                if avatar == None:
                    # likely to be an actual img element
                    avatar = soup.find("img", {"class":"avatar"}).get("src")
                else:
                    avatar = avatar.get("value")


                avatarlocal = None
                if DOWNLOAD_AVATARS:
                    # download the avatar
                    try:
                        r = requests.get(avatar, allow_redirects=True)
                        content_type = r.headers['content-type']
                        extension = mimetypes.guess_extension(content_type)
                        avatarlocal = str(userID) + extension
                        open('avatar/' + avatarlocal, 'wb').write(r.content)
                    except:
                        traceback.print_exc()
                        print avatar
                        print "continuing, without local avatar"
                        avatarlocal = None


                photolocal = None
                if DOWNLOAD_PHOTOS:
                    # download the photo
                    try:
                        r = requests.get(photo, allow_redirects=True)
                        content_type = r.headers['content-type']
                        extension = mimetypes.guess_extension(content_type)
                        photolocal = str(userID) + extension
                        open('avatar/' + photolocal, 'wb').write(r.content)
                    except:
                        traceback.print_exc()
                        print photo
                        print "continuing, without local photo"
                        photolocal = None

                interests = ""
                if interestsArr != []:
                    interests = interestsArr[0]

                signaturebbcode = ""
                if signaturebbcodeArr != []:
                    signaturebbcode = signaturebbcodeArr[0]


                # scrape the profile page
                r = requests.get(userUrl, cookies=COOKIE)
                if r.status_code != 200:
                    print bcolors.FAIL + "Non-200 status code scraping " + url + bcolors.ENDC
                    sys.exit(0)

                # parse webpage
                soup = BeautifulSoup(r.text, "html.parser")

                birthday = ""
                preBirthday = soup.find("td", string="Birthday:")
                try:
                    if preBirthday != None:
                        birthday = preBirthday.find_next("td").text

                        if "," in birthday:
                            # it's the full birthday
                            birthday = datetime.datetime.strptime(birthday, "%B %d, %Y")
                            year = '{:>4}'.format(str(birthday.year))
                            month = '{:>2}'.format(str(birthday.month))
                            day = '{:>2}'.format(str(birthday.day))

                        else:
                            # year is hidden
                            birthday = datetime.datetime.strptime(birthday + ", 1984", "%B %d, %Y")
                            year = "   0"
                            month = '{:>2}'.format(str(birthday.month))
                            day = '{:>2}'.format(str(birthday.day))

                        birthday = day + "-" + month + "-" + year
                except:
                    traceback.print_exc()
                    print "skipping error"
                    birthday = ""

                signaturehtml = soup.find("td", {"class":"c_sig"}).decode_contents().strip()
                number = int(soup.find("dl", {"class":"user_info"}).find("dt", string="Member").find_next("dd").text.replace("#", "").replace(",", ""))
                group = soup.find("dl", {"class":"user_info"}).find("dt", string="Group:").find_next("dd").text

                #joined = soup.find("dl", {"class":"user_info"}).find("dt", string="Joined:").find_next("dd").text
                #joindate = datetime.datetime.strptime(joined, "%B %Y")
                #joindate = joindate.strftime('%s')
                try:
                    joindate = getTapatalkJoinDate(number, username)
                except:
                    traceback.print_exc()
                    print "Error getting tapatalk join date for member id " + str(userID)
                    print "getting zeta join date instead"
                    joined = soup.find("dl", {"class":"user_info"}).find("dt", string="Joined:").find_next("dd").text
                    joindate = datetime.datetime.strptime(joined, "%B %Y")
                    joindate = joindate.strftime('%s')

                localTime = soup.find("td", string="Member's Local Time").find_next("td").text
                localTime = datetime.datetime.strptime(localTime, "%b %d %Y, %I:%M %p")
                utcTime = datetime.datetime.utcnow()
                difference = localTime - utcTime
                secondsDifference = difference.total_seconds()
                secondsDifference = int(60 * round(float(secondsDifference)/60)) # round to nearest minute
                hourDifference = secondsDifference/3600.0

                lastActiveStr = soup.find("td", string="Last Activity").find_next("td").text
                lastActive = parseDate(lastActiveStr)


                # not supported by this tool
                password = None
                permission = None

                # insert into the database
                values = (userID, username, password, email, birthday, number, joindate, ip, group, title, warning, pms, ipbans, photo, photolocal, avatar, avatarlocal, interests, signaturebbcode, signaturehtml, location, aol, yahoo, msn, homepage, lastActive, hourDifference, numPosts)
                cursor.execute('INSERT INTO member VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', values)
                conn.commit()
                print bcolors.OKGREEN + "Inserted user ID: " + str(userID) + bcolors.ENDC


def getTapatalkJoinDate(userNumber, username):
    if not USE_TAPATALK_JOIN_DATE:
        raise Exception('Tapatalk is being annoying, skip this for now and use zeta join dates')
    
    # something good about tapatalk! we can get the FULL join date from them!
    url = TAPATALK_URL + "memberlist.php?mode=viewprofile&u=" + str(userNumber)
    r = requests.get(url, cookies=COOKIE_ADMIN, timeout=60)
    if r.status_code != 200:
        if userNumber < 246:
            print "Can't get tapatalk join date"
            return 0
        # try the user below us
        return getTapatalkJoinDate(userNumber - 1, username)

    # parse webpage
    soup = BeautifulSoup(r.text, "html.parser")

    print url

    tapatalkUsername = soup.find("div", {"class":"username"}).find_next("span").text

    if username.lower() != tapatalkUsername.lower():
        if userNumber < 246:
            print "Can't get tapatalk join date"
            return 0
        # try the user below us
        return getTapatalkJoinDate(userNumber - 1, username)

    # get tapatalk specific data
    #birthdayString = soup.find("span", string="birthday").find_next("span").text
    #birthday = parseDate(birthday)

    joinedString = soup.find("span", string="Joined").find_next("span").text
    joinedString = joinedString.replace("th, ", " ").replace("st, ", "").replace("nd, ", "")
    joined = datetime.datetime.strptime("April 12 2014, 1:44 pm", "%B %d %Y, %I:%M %p")
    joined = joined.strftime('%s')

    return joined


def scrapeEmojis():
    print "(6/5) ########## SCRAPING EMOJI #########"
    # scrape the emojis page
    url = BOARD_URL + "keys"
    r = requests.get(url, cookies=COOKIE)
    if r.status_code != 200:
        print bcolors.FAIL + "Non-200 status code scraping " + url + bcolors.ENDC
        sys.exit(0)

    # parse webpage
    soup = BeautifulSoup(r.text, "html.parser")

    # loop through all emojis
    count = 1
    trs = soup.find_all("tr")
    for tr in trs:
        img = tr.find("img").get("src")
        text = tr.find_all("td")[1].text

        # save the emoji to a file
        r = requests.get(img, allow_redirects=True)
        content_type = r.headers['content-type']
        extension = mimetypes.guess_extension(content_type)
        filename = str(count) + extension
        open('emoji/' + filename, 'wb').write(r.content)

        # save to database
        values = (count, text, img, filename)
        cursor.execute('INSERT INTO emoji VALUES (?,?,?,?)', values)
        conn.commit()
        print values
        count = count + 1


# create sqlite database connection
conn = sqlite3.connect('database.db')
cursor = conn.cursor()

setupDatabase()
scrapeBoard()
scrapeForums()
scrapeTopics()
scrapePosts()
scrapeMembers()
scrapeEmojis()
