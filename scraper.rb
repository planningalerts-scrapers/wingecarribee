require "masterview_scraper"

url = "https://datracker.wsc.nsw.gov.au/Modules/applicationmaster/default.aspx?page=found&1=thisweek&4a=WLUA,82AReview,CDC,DA,Mods&6=F"

MasterviewScraper.scrape(url) do |record|
  MasterviewScraper.save(record)
end
