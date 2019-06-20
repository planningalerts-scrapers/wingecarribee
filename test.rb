# A simple regression test
# Simulates a fixed external website using data from fixtures
# Checks that the data is as expected

require 'vcr'
require 'scraperwiki'
require 'yaml'
require 'timecop'

VCR.configure do |config|
  config.cassette_library_dir = "fixtures/vcr_cassettes"
  config.hook_into :webmock
end

File.delete("./data.sqlite") if File.exist?("./data.sqlite")

system("php scraper.php")

results_other = ScraperWiki.select("* from data order by council_reference")
results_other = results_other.map do |result|
  result.delete("id")
  result
end
File.open("results_other.yml", "w") do |f|
  f.write(results_other.to_yaml)
end

ScraperWiki.close_sqlite

File.delete("./data.sqlite") if File.exist?("./data.sqlite")

system("bundle exec ruby scraper.rb")

results_ruby = ScraperWiki.select("* from data order by council_reference")
File.open("results_ruby.yml", "w") do |f|
  f.write(results_ruby.to_yaml)
end

unless results_other == results_ruby
  system("diff results_other.yml results_ruby.yml")
  raise "Failed"
end
puts "Succeeded"
