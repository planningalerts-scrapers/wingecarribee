require "masterview_scraper"

MasterviewScraper.scrape_and_save_period(
  url: "https://datracker.wsc.nsw.gov.au/Modules/applicationmaster",
  period: :thisweek,
  params: {
    "4a" => "WLUA,82AReview,CDC,DA,Mods",
    "6" => "F"
  }
)
