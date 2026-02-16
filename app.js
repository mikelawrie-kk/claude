// State management
const state = {
    selectedHotels: [],
    suggestedHotels: [],
    currentFocusIndex: -1
};

// DOM elements
const hotelSearchInput = document.getElementById('hotelSearch');
const autocompleteList = document.getElementById('autocompleteList');
const suggestedHotelsContainer = document.getElementById('suggestedHotels');
const selectedHotelsContainer = document.getElementById('selectedHotels');
const clearAllButton = document.getElementById('clearAll');
const saveSelectionButton = document.getElementById('saveSelection');

// Initialize the application
function init() {
    // Load suggested hotels (random, not defaults)
    loadSuggestedHotels();

    // Setup event listeners
    hotelSearchInput.addEventListener('input', handleSearchInput);
    hotelSearchInput.addEventListener('keydown', handleKeyboardNavigation);
    clearAllButton.addEventListener('click', clearAllSelections);
    saveSelectionButton.addEventListener('click', saveSelection);

    // Close autocomplete when clicking outside
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.search-container')) {
            hideAutocomplete();
        }
    });

    updateUI();
}

// Load suggested hotels for user approval
function loadSuggestedHotels() {
    state.suggestedHotels = getRandomSuggestedHotels(5);
    renderSuggestedHotels();
}

// Render suggested hotels with approve/reject buttons
function renderSuggestedHotels() {
    if (state.suggestedHotels.length === 0) {
        suggestedHotelsContainer.innerHTML = '<div class="empty-state">No suggestions available</div>';
        return;
    }

    suggestedHotelsContainer.innerHTML = state.suggestedHotels.map(hotel => `
        <div class="suggested-hotel-item" data-hotel-id="${hotel.id}">
            <div class="hotel-info">
                <div class="hotel-name">${hotel.name}</div>
                <div class="hotel-location">${hotel.location}</div>
            </div>
            <div class="suggested-actions">
                <button class="btn btn-danger btn-sm" onclick="rejectHotel(${hotel.id})">Reject</button>
                <button class="btn btn-success btn-sm" onclick="approveHotel(${hotel.id})">Approve</button>
            </div>
        </div>
    `).join('');
}

// Approve a suggested hotel
function approveHotel(hotelId) {
    const hotel = state.suggestedHotels.find(h => h.id === hotelId);
    if (hotel && !isHotelSelected(hotel.id)) {
        state.selectedHotels.push(hotel);
        state.suggestedHotels = state.suggestedHotels.filter(h => h.id !== hotelId);
        renderSuggestedHotels();
        renderSelectedHotels();
        updateUI();
    }
}

// Reject a suggested hotel
function rejectHotel(hotelId) {
    state.suggestedHotels = state.suggestedHotels.filter(h => h.id !== hotelId);
    renderSuggestedHotels();
}

// Handle search input for autocomplete
function handleSearchInput(e) {
    const searchTerm = e.target.value.trim();

    if (searchTerm.length === 0) {
        hideAutocomplete();
        return;
    }

    // Filter hotels based on search term
    const matches = HOTELS_DATABASE.filter(hotel =>
        hotel.name.toLowerCase().includes(searchTerm.toLowerCase()) ||
        hotel.location.toLowerCase().includes(searchTerm.toLowerCase())
    );

    if (matches.length > 0) {
        showAutocomplete(matches);
    } else {
        hideAutocomplete();
    }
}

// Show autocomplete dropdown
function showAutocomplete(hotels) {
    state.currentFocusIndex = -1;

    autocompleteList.innerHTML = hotels.map((hotel, index) => `
        <div class="autocomplete-item" data-hotel-id="${hotel.id}" data-index="${index}">
            <div class="autocomplete-hotel-name">${hotel.name}</div>
            <div class="autocomplete-hotel-location">${hotel.location}</div>
        </div>
    `).join('');

    autocompleteList.classList.add('show');

    // Add click listeners to autocomplete items
    const items = autocompleteList.querySelectorAll('.autocomplete-item');
    items.forEach(item => {
        item.addEventListener('click', function() {
            const hotelId = parseInt(this.dataset.hotelId);
            selectHotelFromAutocomplete(hotelId);
        });
    });
}

// Hide autocomplete dropdown
function hideAutocomplete() {
    autocompleteList.classList.remove('show');
    autocompleteList.innerHTML = '';
    state.currentFocusIndex = -1;
}

// Handle keyboard navigation in autocomplete
function handleKeyboardNavigation(e) {
    const items = autocompleteList.querySelectorAll('.autocomplete-item');

    if (items.length === 0) return;

    if (e.key === 'ArrowDown') {
        e.preventDefault();
        state.currentFocusIndex++;
        if (state.currentFocusIndex >= items.length) state.currentFocusIndex = 0;
        setActiveItem(items);
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        state.currentFocusIndex--;
        if (state.currentFocusIndex < 0) state.currentFocusIndex = items.length - 1;
        setActiveItem(items);
    } else if (e.key === 'Enter') {
        e.preventDefault();
        if (state.currentFocusIndex >= 0 && items[state.currentFocusIndex]) {
            const hotelId = parseInt(items[state.currentFocusIndex].dataset.hotelId);
            selectHotelFromAutocomplete(hotelId);
        }
    } else if (e.key === 'Escape') {
        hideAutocomplete();
    }
}

// Set active item in autocomplete
function setActiveItem(items) {
    items.forEach((item, index) => {
        if (index === state.currentFocusIndex) {
            item.classList.add('active');
            item.scrollIntoView({ block: 'nearest' });
        } else {
            item.classList.remove('active');
        }
    });
}

// Select hotel from autocomplete
function selectHotelFromAutocomplete(hotelId) {
    const hotel = HOTELS_DATABASE.find(h => h.id === hotelId);

    if (hotel && !isHotelSelected(hotel.id)) {
        state.selectedHotels.push(hotel);
        renderSelectedHotels();
        updateUI();
    }

    hotelSearchInput.value = '';
    hideAutocomplete();
}

// Check if hotel is already selected
function isHotelSelected(hotelId) {
    return state.selectedHotels.some(h => h.id === hotelId);
}

// Render selected hotels
function renderSelectedHotels() {
    if (state.selectedHotels.length === 0) {
        selectedHotelsContainer.innerHTML = '<div class="empty-state">No hotels selected yet</div>';
        return;
    }

    selectedHotelsContainer.innerHTML = state.selectedHotels.map(hotel => `
        <div class="selected-hotel-item" data-hotel-id="${hotel.id}">
            <div class="hotel-info">
                <div class="selected-hotel-name">${hotel.name}</div>
                <div class="selected-hotel-location">${hotel.location}</div>
            </div>
            <button class="btn btn-remove" onclick="removeHotel(${hotel.id})">Remove</button>
        </div>
    `).join('');
}

// Remove a selected hotel
function removeHotel(hotelId) {
    state.selectedHotels = state.selectedHotels.filter(h => h.id !== hotelId);
    renderSelectedHotels();
    updateUI();
}

// Clear all selections
function clearAllSelections() {
    if (state.selectedHotels.length === 0) return;

    if (confirm('Are you sure you want to clear all selected hotels?')) {
        state.selectedHotels = [];
        renderSelectedHotels();
        updateUI();
    }
}

// Save selection
function saveSelection() {
    if (state.selectedHotels.length === 0) {
        alert('Please select at least one competitor hotel.');
        return;
    }

    console.log('Saving selected hotels:', state.selectedHotels);
    alert(`Successfully saved ${state.selectedHotels.length} competitor hotel(s)!`);

    // Here you would typically send this data to a backend API
    // Example: fetch('/api/save-competitors', { method: 'POST', body: JSON.stringify(state.selectedHotels) })
}

// Update UI state
function updateUI() {
    const infoText = document.querySelector('.selected-section .info-text');
    if (state.selectedHotels.length === 0) {
        infoText.textContent = 'No hotels selected yet';
    } else {
        infoText.textContent = `${state.selectedHotels.length} hotel(s) selected`;
    }

    saveSelectionButton.disabled = state.selectedHotels.length === 0;
}

// Initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
