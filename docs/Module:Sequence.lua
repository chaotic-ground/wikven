-- The order of the documentation, read from the one place it is written down.
--
-- MediaWiki:Sidebar lists the pages in order, and Template:prevnext used to list them again as its
-- arguments. Nothing checked that the two agreed, and twice they did not: a page was added to the
-- sidebar without a chain entry, and the chain's last page was left with no row at all (#455). Both
-- are the same failure -- adding a page meant editing three files and forgetting one was invisible.
--
-- The direction is forced. MediaWiki:Sidebar is read line by line by the skin rather than parsed as
-- wikitext, so it cannot call anything; but it is an ordinary page, so anything can read it.
--
-- What the sidebar contributes is order alone. The link labels stay where they were, in
-- Template:prevnext/label, which reads each target's own translated title.
--
-- Named with the .lua marker, which Special:MyLanguage/Lua modules describes without recommending:
-- the page is Module:Sequence.lua, and Template:prevnext spells the invoke that way.
local p = {}

-- Where the order lives, and the entry line to read out of it: "** Special:MyLanguage/Page|key".
local SIDEBAR = 'MediaWiki:Sidebar'
local ENTRY = '^%*%*%s*Special:MyLanguage/([^|\n]+)'

-- Both sidebar groups, in one sequence. The chain has always crossed from the docs group into the
-- references group, and a reader at the end of the first group is better sent on than stopped.
local function sequence()
	local sidebar = mw.title.new(SIDEBAR)
	local content = sidebar and sidebar:getContent()
	local pages = {}
	if content then
		for line in mw.text.gsplit(content, '\n') do
			local target = line:match(ENTRY)
			if target then
				pages[#pages + 1] = mw.text.trim(target)
			end
		end
	end
	return pages
end

-- Where this page sits in the sequence, or nil if the sidebar does not name it -- which is every
-- page that is not a step of the documentation. The main page is one of those: it is where a reader
-- arrives rather than a step they walk, and it names its own way on in its opening lines.
--
-- A translation counts as its source page: "Searching/ko" is the sidebar's "Searching" in another
-- language, and the sidebar names the page it was translated from. Matched as a prefix rather than
-- by asking the title for its root, because rootText only strips a subpage where the namespace has
-- them turned on, and this one does not -- there, "Searching/ko" is one whole title. Anchoring on
-- the names the sidebar gives keeps that out of it: no entry is another entry plus a slash.
local function positionOf(pages, here)
	for i, page in ipairs(pages) do
		if here == page or here:sub(1, #page + 1) == page .. '/' then
			return i
		end
	end
	return nil
end

-- The page on either side of this one, or nil at an end of the sequence.
local function neighbour(step)
	local pages = sequence()
	-- Loud rather than empty. A row that renders as nothing is the failure this module exists to
	-- end, and it is the invisible kind: the wrapper div is still there and the page still looks
	-- finished. If the sidebar stops being readable, that should stop a build, not reach a reader.
	if #pages == 0 then
		error(SIDEBAR .. ' has no "** Special:MyLanguage/Page" entries to read the order from', 0)
	end
	local here = positionOf(pages, mw.title.getCurrentTitle().text)
	return here and pages[here + step] or nil
end

local function link(frame, page, before, after)
	local label = frame:expandTemplate{ title = 'prevnext/label', args = { page } }
	return before .. '[[Special:MyLanguage/' .. page .. '|' .. label .. ']]' .. after
end

-- The row: a previous link, a next link, or both. A page the sidebar does not name gets neither,
-- which is what every page outside the sequence -- a template, a category, a File: page -- should
-- get if it ever calls this.
function p.row(frame)
	local out = {}
	local previous = neighbour(-1)
	if previous then
		out[#out + 1] = '<div class="wikven-prevnext-prev">' .. link(frame, previous, '&larr;&nbsp;', '') .. '</div>'
	end
	local following = neighbour(1)
	if following then
		out[#out + 1] = '<div class="wikven-prevnext-next">' .. link(frame, following, '', '&nbsp;&rarr;') .. '</div>'
	end
	return table.concat(out)
end

return p
