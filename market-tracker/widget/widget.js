/**
 * Filename: widget.js
 * Description: Embeddable vanilla JS widget for public price comparison display
 * Version: 1.0.0
 * Created: 2026-02-16 12:15:00 SAST
 * Modified: 2026-02-16 12:15:00 SAST
 *
 * Usage:
 * <div id="market-tracker-widget" data-check-in="2026-04-01"></div>
 * <script src="https://yourdomain.com/market-tracker/widget/widget.js"></script>
 */

(function() {
    'use strict';

    // Configuration
    const API_BASE_URL = getApiBaseUrl();

    // Initialize widget
    function initWidget() {
        const container = document.getElementById('market-tracker-widget');

        if (!container) {
            console.error('Market Tracker Widget: Container element not found');
            return;
        }

        const checkIn = container.getAttribute('data-check-in') || getDefaultCheckIn();
        const checkOut = container.getAttribute('data-check-out') || getDefaultCheckOut(checkIn);

        loadWidgetData(container, checkIn, checkOut);
    }

    // Load widget data from API
    function loadWidgetData(container, checkIn, checkOut) {
        const url = `${API_BASE_URL}/widget/embed.php?check_in=${checkIn}&check_out=${checkOut}`;

        fetch(url)
            .then(response => response.json())
            .then(data => {
                if (data.success && data.show_widget) {
                    renderWidget(container, data);
                } else {
                    // Don't display widget if not cheaper
                    container.style.display = 'none';
                }
            })
            .catch(error => {
                console.error('Market Tracker Widget: Failed to load data', error);
                container.style.display = 'none';
            });
    }

    // Render widget HTML
    function renderWidget(container, data) {
        const html = `
            <div class="mtw-container">
                <div class="mtw-header">
                    <div class="mtw-icon">💰</div>
                    <div class="mtw-title">Save by Booking Direct</div>
                </div>

                <div class="mtw-comparison">
                    <div class="mtw-price mtw-direct">
                        <div class="mtw-label">Book Direct</div>
                        <div class="mtw-amount">${data.direct_price.formatted}</div>
                        <div class="mtw-badge mtw-best">Best Price</div>
                    </div>

                    <div class="mtw-vs">vs</div>

                    <div class="mtw-price mtw-ota">
                        <div class="mtw-label">${escapeHtml(data.ota_price.source)}</div>
                        <div class="mtw-amount">${data.ota_price.formatted}</div>
                    </div>
                </div>

                <div class="mtw-savings">
                    <strong>Save ${data.savings.formatted}</strong> (${data.savings.percentage}% off)
                </div>

                <div class="mtw-footer">
                    <small>${escapeHtml(data.disclaimer)}</small>
                </div>
            </div>
        `;

        container.innerHTML = html + getWidgetStyles();
        container.style.display = 'block';
    }

    // Get widget styles
    function getWidgetStyles() {
        return `
            <style>
                .mtw-container {
                    font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
                    background: linear-gradient(135deg, #FFFDF7 0%, #FFF8E7 100%);
                    border: 2px solid #D4A843;
                    border-radius: 16px;
                    padding: 24px;
                    max-width: 400px;
                    margin: 20px auto;
                    box-shadow: 0 4px 20px rgba(212, 168, 67, 0.15);
                }

                .mtw-header {
                    display: flex;
                    align-items: center;
                    gap: 12px;
                    margin-bottom: 20px;
                }

                .mtw-icon {
                    font-size: 28px;
                }

                .mtw-title {
                    font-size: 18px;
                    font-weight: 600;
                    color: #333;
                }

                .mtw-comparison {
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                    gap: 15px;
                    margin-bottom: 20px;
                }

                .mtw-price {
                    flex: 1;
                    text-align: center;
                    padding: 16px;
                    border-radius: 12px;
                    background: white;
                }

                .mtw-direct {
                    border: 2px solid #D4A843;
                    position: relative;
                }

                .mtw-ota {
                    border: 2px solid #e0e0e0;
                }

                .mtw-label {
                    font-size: 12px;
                    color: #666;
                    margin-bottom: 8px;
                    font-weight: 500;
                    text-transform: uppercase;
                }

                .mtw-amount {
                    font-size: 24px;
                    font-weight: 700;
                    color: #333;
                }

                .mtw-direct .mtw-amount {
                    color: #D4A843;
                }

                .mtw-badge {
                    margin-top: 8px;
                    font-size: 11px;
                    font-weight: 600;
                    text-transform: uppercase;
                }

                .mtw-best {
                    color: #2e7d32;
                }

                .mtw-vs {
                    font-size: 14px;
                    font-weight: 600;
                    color: #999;
                    flex-shrink: 0;
                }

                .mtw-savings {
                    text-align: center;
                    padding: 12px;
                    background: linear-gradient(135deg, #D4A843 0%, #C49835 100%);
                    color: white;
                    border-radius: 10px;
                    font-size: 16px;
                    margin-bottom: 15px;
                }

                .mtw-footer {
                    text-align: center;
                    color: #666;
                    font-size: 11px;
                }

                @media (max-width: 480px) {
                    .mtw-comparison {
                        flex-direction: column;
                    }

                    .mtw-vs {
                        transform: rotate(90deg);
                    }

                    .mtw-price {
                        width: 100%;
                    }
                }
            </style>
        `;
    }

    // Helper functions
    function getApiBaseUrl() {
        // Get the URL from the script tag's src attribute
        const scripts = document.getElementsByTagName('script');
        for (let i = 0; i < scripts.length; i++) {
            const src = scripts[i].src;
            if (src.indexOf('widget.js') !== -1) {
                return src.replace('/widget/widget.js', '');
            }
        }
        return '';
    }

    function getDefaultCheckIn() {
        const date = new Date();
        date.setDate(date.getDate() + 7);
        return formatDate(date);
    }

    function getDefaultCheckOut(checkIn) {
        const date = new Date(checkIn);
        date.setDate(date.getDate() + 1);
        return formatDate(date);
    }

    function formatDate(date) {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Auto-initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initWidget);
    } else {
        initWidget();
    }

})();
