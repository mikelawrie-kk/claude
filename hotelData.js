// Sample hotel database for autocomplete and suggestions
const HOTELS_DATABASE = [
    { id: 1, name: "Grand Hyatt", location: "New York, NY" },
    { id: 2, name: "Marriott Marquis", location: "New York, NY" },
    { id: 3, name: "Hilton Garden Inn", location: "San Francisco, CA" },
    { id: 4, name: "The Ritz-Carlton", location: "Los Angeles, CA" },
    { id: 5, name: "Four Seasons Hotel", location: "Chicago, IL" },
    { id: 6, name: "Waldorf Astoria", location: "New York, NY" },
    { id: 7, name: "Sheraton Grand", location: "Seattle, WA" },
    { id: 8, name: "InterContinental", location: "Boston, MA" },
    { id: 9, name: "W Hotel", location: "Miami, FL" },
    { id: 10, name: "The Peninsula", location: "Beverly Hills, CA" },
    { id: 11, name: "Mandarin Oriental", location: "Las Vegas, NV" },
    { id: 12, name: "St. Regis", location: "San Francisco, CA" },
    { id: 13, name: "JW Marriott", location: "Austin, TX" },
    { id: 14, name: "Fairmont Hotel", location: "San Francisco, CA" },
    { id: 15, name: "Park Hyatt", location: "Chicago, IL" },
    { id: 16, name: "Westin Bonaventure", location: "Los Angeles, CA" },
    { id: 17, name: "Loews Regency", location: "San Francisco, CA" },
    { id: 18, name: "Kimpton Hotel", location: "Portland, OR" },
    { id: 19, name: "Renaissance Hotel", location: "Denver, CO" },
    { id: 20, name: "Omni Hotel", location: "Dallas, TX" },
    { id: 21, name: "Hyatt Regency", location: "Atlanta, GA" },
    { id: 22, name: "DoubleTree by Hilton", location: "Phoenix, AZ" },
    { id: 23, name: "Embassy Suites", location: "Orlando, FL" },
    { id: 24, name: "Courtyard by Marriott", location: "Nashville, TN" },
    { id: 25, name: "Hampton Inn", location: "Washington, DC" },
    { id: 26, name: "Holiday Inn Express", location: "Philadelphia, PA" },
    { id: 27, name: "Best Western Plus", location: "San Diego, CA" },
    { id: 28, name: "Crowne Plaza", location: "Minneapolis, MN" },
    { id: 29, name: "Radisson Hotel", location: "Salt Lake City, UT" },
    { id: 30, name: "Sofitel", location: "New York, NY" },
    { id: 31, name: "Aloft Hotel", location: "Brooklyn, NY" },
    { id: 32, name: "Le Meridien", location: "San Francisco, CA" },
    { id: 33, name: "Andaz Hotel", location: "San Diego, CA" },
    { id: 34, name: "Conrad Hotel", location: "Washington, DC" },
    { id: 35, name: "Thompson Hotel", location: "Nashville, TN" },
    { id: 36, name: "Ace Hotel", location: "Portland, OR" },
    { id: 37, name: "citizenM", location: "Boston, MA" },
    { id: 38, name: "Moxy Hotel", location: "Chicago, IL" },
    { id: 39, name: "Canopy by Hilton", location: "Austin, TX" },
    { id: 40, name: "Tribute Portfolio", location: "Miami, FL" }
];

// Function to get random suggested hotels (not hardcoded defaults)
function getRandomSuggestedHotels(count = 5) {
    const shuffled = [...HOTELS_DATABASE].sort(() => 0.5 - Math.random());
    return shuffled.slice(0, count);
}
