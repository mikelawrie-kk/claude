/**
 * Filename: app.js
 * Description: Main JavaScript for Safari Traveller public and admin interfaces
 * Project: Safari Traveller - African Property Harvester
 * Version: 1.0.0
 * Created: 2026-02-17 12:00 SAST
 * Modified: 2026-02-17 12:00 SAST
 * Changes: Initial creation
 */

(function () {
    'use strict';

    /* ================================================================
       Utility: Debounce
       ================================================================ */
    function debounce(fn, delay) {
        var timer = null;
        return function () {
            var context = this;
            var args = arguments;
            clearTimeout(timer);
            timer = setTimeout(function () {
                fn.apply(context, args);
            }, delay);
        };
    }

    /* ================================================================
       Utility: Throttle
       ================================================================ */
    function throttle(fn, limit) {
        var waiting = false;
        return function () {
            if (!waiting) {
                fn.apply(this, arguments);
                waiting = true;
                setTimeout(function () {
                    waiting = false;
                }, limit);
            }
        };
    }

    /* ================================================================
       Mobile Navigation Toggle
       ================================================================ */
    function initMobileNav() {
        var toggle = document.querySelector('.navbar-toggle');
        var mobileMenu = document.querySelector('.navbar-mobile');

        if (!toggle || !mobileMenu) return;

        toggle.addEventListener('click', function () {
            toggle.classList.toggle('active');
            mobileMenu.classList.toggle('open');
            document.body.style.overflow = mobileMenu.classList.contains('open') ? 'hidden' : '';
        });

        // Close mobile menu when a nav link is clicked
        var mobileLinks = mobileMenu.querySelectorAll('a');
        mobileLinks.forEach(function (link) {
            link.addEventListener('click', function () {
                toggle.classList.remove('active');
                mobileMenu.classList.remove('open');
                document.body.style.overflow = '';
            });
        });

        // Close mobile menu on window resize if it becomes desktop width
        window.addEventListener('resize', debounce(function () {
            if (window.innerWidth >= 1024) {
                toggle.classList.remove('active');
                mobileMenu.classList.remove('open');
                document.body.style.overflow = '';
            }
        }, 150));
    }

    /* ================================================================
       Search Form Handling with Debounce
       ================================================================ */
    function initSearchForm() {
        var searchInput = document.querySelector('.hero-search-input');
        var searchForm = document.querySelector('.hero-search');

        if (!searchInput || !searchForm) return;

        // Live search suggestions (debounced)
        var suggestionsContainer = document.querySelector('.search-suggestions');

        var handleSearchInput = debounce(function () {
            var query = searchInput.value.trim();

            if (query.length < 2) {
                if (suggestionsContainer) {
                    suggestionsContainer.innerHTML = '';
                    suggestionsContainer.style.display = 'none';
                }
                return;
            }

            // Fetch search suggestions
            fetch('/public/search.php?ajax=1&q=' + encodeURIComponent(query))
                .then(function (response) {
                    return response.json();
                })
                .then(function (data) {
                    if (!suggestionsContainer) return;
                    if (data.results && data.results.length > 0) {
                        var html = '';
                        data.results.forEach(function (item) {
                            html += '<a class="search-suggestion-item" href="' + escapeHtml(item.url) + '">';
                            html += '<span class="suggestion-name">' + escapeHtml(item.name) + '</span>';
                            html += '<span class="suggestion-location">' + escapeHtml(item.location) + '</span>';
                            html += '</a>';
                        });
                        suggestionsContainer.innerHTML = html;
                        suggestionsContainer.style.display = 'block';
                    } else {
                        suggestionsContainer.innerHTML = '<div class="search-suggestion-empty">No results found</div>';
                        suggestionsContainer.style.display = 'block';
                    }
                })
                .catch(function () {
                    // Silently fail on search suggestion errors
                });
        }, 300);

        searchInput.addEventListener('input', handleSearchInput);

        // Handle form submission
        searchForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var query = searchInput.value.trim();
            if (query.length > 0) {
                window.location.href = '/public/search.php?q=' + encodeURIComponent(query);
            }
        });

        // Close suggestions on click outside
        document.addEventListener('click', function (e) {
            if (suggestionsContainer && !searchForm.contains(e.target)) {
                suggestionsContainer.style.display = 'none';
            }
        });
    }

    /* ================================================================
       Inline Search Input (search page)
       ================================================================ */
    function initInlineSearch() {
        var searchInput = document.querySelector('#search-input');
        if (!searchInput) return;

        var form = searchInput.closest('form');
        if (!form) return;

        var handleSearch = debounce(function () {
            form.submit();
        }, 500);

        searchInput.addEventListener('input', handleSearch);
    }

    /* ================================================================
       Score Gauge Animation (animate on scroll into view)
       ================================================================ */
    function initScoreGauges() {
        var gauges = document.querySelectorAll('.score-gauge');
        if (gauges.length === 0) return;

        // Circle circumference: 2 * PI * r where r = 65 (for 160px gauge)
        var circumference = 2 * Math.PI * 65;

        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    var gauge = entry.target;
                    var fill = gauge.querySelector('.score-gauge-fill');
                    var valueEl = gauge.querySelector('.score-value-number');

                    if (!fill || !valueEl) return;

                    var score = parseInt(valueEl.getAttribute('data-score') || valueEl.textContent, 10);
                    if (isNaN(score)) return;

                    var maxScore = parseInt(valueEl.getAttribute('data-max') || '100', 10);
                    var percentage = score / maxScore;

                    // Determine the SVG circle circumference from the element
                    var dashArray = parseFloat(fill.style.strokeDasharray || fill.getAttribute('stroke-dasharray') || circumference);
                    var offset = dashArray - (dashArray * percentage);

                    // Animate the gauge fill
                    fill.style.strokeDasharray = dashArray;
                    fill.style.strokeDashoffset = offset;

                    // Animate the number counting up
                    animateCounter(valueEl, 0, score, 1200);

                    // Apply colour class based on score
                    if (percentage >= 0.7) {
                        fill.classList.add('score-high');
                    } else if (percentage >= 0.4) {
                        fill.classList.add('score-mid');
                    } else {
                        fill.classList.add('score-low');
                    }

                    observer.unobserve(gauge);
                }
            });
        }, { threshold: 0.3 });

        gauges.forEach(function (gauge) {
            observer.observe(gauge);
        });
    }

    /**
     * Animate a counter from start to end over a duration (ms).
     */
    function animateCounter(el, start, end, duration) {
        var startTime = null;

        function step(timestamp) {
            if (!startTime) startTime = timestamp;
            var progress = Math.min((timestamp - startTime) / duration, 1);
            var current = Math.floor(progress * (end - start) + start);
            el.textContent = current;
            if (progress < 1) {
                requestAnimationFrame(step);
            } else {
                el.textContent = end;
            }
        }

        requestAnimationFrame(step);
    }

    /* ================================================================
       Score Category Progress Bar Animation
       ================================================================ */
    function initScoreBars() {
        var bars = document.querySelectorAll('.score-category-bar-fill');
        if (bars.length === 0) return;

        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    var bar = entry.target;
                    var targetWidth = bar.getAttribute('data-width') || '0';
                    bar.style.width = targetWidth + '%';
                    observer.unobserve(bar);
                }
            });
        }, { threshold: 0.2 });

        bars.forEach(function (bar) {
            observer.observe(bar);
        });
    }

    /* ================================================================
       Filter Panel Toggle (Mobile)
       ================================================================ */
    function initFilterPanel() {
        var toggleBtn = document.querySelector('.search-panel-toggle');
        var panelBody = document.querySelector('.search-panel-body');

        if (!toggleBtn || !panelBody) return;

        toggleBtn.addEventListener('click', function () {
            panelBody.classList.toggle('open');
            var isOpen = panelBody.classList.contains('open');
            toggleBtn.setAttribute('aria-expanded', isOpen);
            toggleBtn.textContent = isOpen ? 'Hide Filters' : 'Show Filters';
        });

        // Filter chips - toggle active state
        var chips = document.querySelectorAll('.filter-chip');
        chips.forEach(function (chip) {
            chip.addEventListener('click', function () {
                chip.classList.toggle('active');
            });
        });
    }

    /* ================================================================
       Enquiry Form AJAX Submission with Validation
       ================================================================ */
    function initEnquiryForm() {
        var form = document.querySelector('#enquiry-form');
        if (!form) return;

        form.addEventListener('submit', function (e) {
            e.preventDefault();

            // Clear previous errors
            form.querySelectorAll('.form-error').forEach(function (el) {
                el.textContent = '';
            });
            form.querySelectorAll('.is-invalid').forEach(function (el) {
                el.classList.remove('is-invalid');
            });

            // Validate fields
            var isValid = true;

            var name = form.querySelector('[name="name"]');
            var email = form.querySelector('[name="email"]');
            var message = form.querySelector('[name="message"]');
            var propertyId = form.querySelector('[name="property_id"]');

            if (name && name.value.trim().length < 2) {
                showFieldError(name, 'Please enter your full name.');
                isValid = false;
            }

            if (email && !isValidEmail(email.value)) {
                showFieldError(email, 'Please enter a valid email address.');
                isValid = false;
            }

            if (message && message.value.trim().length < 10) {
                showFieldError(message, 'Please enter a message of at least 10 characters.');
                isValid = false;
            }

            if (!isValid) return;

            // Disable submit button
            var submitBtn = form.querySelector('[type="submit"]');
            var originalText = submitBtn ? submitBtn.textContent : 'Send';
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.textContent = 'Sending...';
            }

            // Build form data
            var formData = new FormData(form);

            // Submit via AJAX
            fetch('/public/enquiry.php', {
                method: 'POST',
                body: formData
            })
                .then(function (response) {
                    return response.json();
                })
                .then(function (data) {
                    if (data.success) {
                        // Show success state
                        var wrapper = form.closest('.enquiry-form-wrapper');
                        if (wrapper) {
                            wrapper.innerHTML =
                                '<div class="enquiry-success">' +
                                '<div class="checkmark">&#10003;</div>' +
                                '<h3>Enquiry Sent!</h3>' +
                                '<p>Thank you for your enquiry. The property will respond to you directly.</p>' +
                                '</div>';
                        }
                        showToast('Enquiry sent successfully!', 'success');
                    } else {
                        showToast(data.message || 'Failed to send enquiry. Please try again.', 'error');
                        if (submitBtn) {
                            submitBtn.disabled = false;
                            submitBtn.textContent = originalText;
                        }
                    }
                })
                .catch(function () {
                    showToast('An error occurred. Please try again later.', 'error');
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.textContent = originalText;
                    }
                });
        });
    }

    /**
     * Show a validation error on a specific form field.
     */
    function showFieldError(field, message) {
        field.classList.add('is-invalid');
        var errorEl = field.parentNode.querySelector('.form-error');
        if (errorEl) {
            errorEl.textContent = message;
        } else {
            var span = document.createElement('span');
            span.className = 'form-error';
            span.textContent = message;
            field.parentNode.appendChild(span);
        }
    }

    /**
     * Basic email validation.
     */
    function isValidEmail(email) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
    }

    /* ================================================================
       Claim Form - Domain Verification
       ================================================================ */
    function initClaimForm() {
        var form = document.querySelector('#claim-form');
        if (!form) return;

        var emailField = form.querySelector('[name="email"]');
        var propertyUrl = form.querySelector('[name="property_url"]');
        var domainNote = form.querySelector('.claim-domain-note');

        if (!emailField || !propertyUrl) return;

        // Extract domain from the property URL
        var propertyDomain = '';
        try {
            var url = new URL(propertyUrl.value);
            propertyDomain = url.hostname.replace(/^www\./, '').toLowerCase();
        } catch (e) {
            // Property URL might not be a valid URL
            propertyDomain = '';
        }

        // Show which domain is expected
        if (domainNote && propertyDomain) {
            domainNote.textContent =
                'Please use an email address from the domain "' + propertyDomain +
                '" to verify your ownership (e.g. info@' + propertyDomain + ').';
        }

        // Validate email domain on input
        emailField.addEventListener('input', debounce(function () {
            var emailValue = emailField.value.trim();
            if (!isValidEmail(emailValue) || !propertyDomain) return;

            var emailDomain = emailValue.split('@')[1].toLowerCase();

            // Strip www. from email domain for comparison
            emailDomain = emailDomain.replace(/^www\./, '');

            if (emailDomain === propertyDomain) {
                emailField.classList.remove('is-invalid');
                emailField.classList.add('is-valid');
                clearFieldError(emailField);
            } else {
                emailField.classList.remove('is-valid');
                emailField.classList.add('is-invalid');
                showFieldError(emailField, 'Email domain must match the property website domain (' + propertyDomain + ').');
            }
        }, 300));

        // Form submission validation
        form.addEventListener('submit', function (e) {
            var emailValue = emailField.value.trim();

            if (!isValidEmail(emailValue)) {
                e.preventDefault();
                showFieldError(emailField, 'Please enter a valid email address.');
                return;
            }

            if (propertyDomain) {
                var emailDomain = emailValue.split('@')[1].toLowerCase().replace(/^www\./, '');
                if (emailDomain !== propertyDomain) {
                    e.preventDefault();
                    showFieldError(emailField, 'Email domain must match the property website domain (' + propertyDomain + ').');
                    return;
                }
            }
        });
    }

    /**
     * Clear validation error from a field.
     */
    function clearFieldError(field) {
        var errorEl = field.parentNode.querySelector('.form-error');
        if (errorEl) {
            errorEl.textContent = '';
        }
    }

    /* ================================================================
       Admin: Bulk Action Checkboxes (Select All/None)
       ================================================================ */
    function initBulkActions() {
        var selectAllCheckbox = document.querySelector('#select-all');
        if (!selectAllCheckbox) return;

        var checkboxes = document.querySelectorAll('.bulk-checkbox');
        var bulkActions = document.querySelector('.bulk-actions');

        selectAllCheckbox.addEventListener('change', function () {
            var checked = selectAllCheckbox.checked;
            checkboxes.forEach(function (cb) {
                cb.checked = checked;
            });
            updateBulkActionsVisibility();
        });

        checkboxes.forEach(function (cb) {
            cb.addEventListener('change', function () {
                // Update "select all" state
                var allChecked = true;
                var anyChecked = false;
                checkboxes.forEach(function (c) {
                    if (!c.checked) allChecked = false;
                    if (c.checked) anyChecked = true;
                });
                selectAllCheckbox.checked = allChecked;
                selectAllCheckbox.indeterminate = anyChecked && !allChecked;
                updateBulkActionsVisibility();
            });
        });

        function updateBulkActionsVisibility() {
            if (!bulkActions) return;
            var anyChecked = false;
            checkboxes.forEach(function (c) {
                if (c.checked) anyChecked = true;
            });
            bulkActions.style.display = anyChecked ? 'flex' : 'none';

            // Update count
            var countEl = bulkActions.querySelector('.bulk-count');
            if (countEl) {
                var count = 0;
                checkboxes.forEach(function (c) {
                    if (c.checked) count++;
                });
                countEl.textContent = count + ' selected';
            }
        }
    }

    /* ================================================================
       Admin: Confirm Dialogs for Destructive Actions
       ================================================================ */
    function initConfirmDialogs() {
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-confirm]');
            if (!btn) return;

            var message = btn.getAttribute('data-confirm') || 'Are you sure you want to perform this action?';

            if (!confirm(message)) {
                e.preventDefault();
                e.stopPropagation();
            }
        });
    }

    /* ================================================================
       Admin: Sidebar Toggle (Mobile)
       ================================================================ */
    function initAdminSidebar() {
        var toggleBtn = document.querySelector('.admin-sidebar-toggle');
        var sidebar = document.querySelector('.admin-sidebar');
        var overlay = document.querySelector('.admin-sidebar-overlay');

        if (!toggleBtn || !sidebar) return;

        toggleBtn.addEventListener('click', function () {
            sidebar.classList.toggle('open');
            if (overlay) overlay.classList.toggle('open');
            document.body.style.overflow = sidebar.classList.contains('open') ? 'hidden' : '';
        });

        if (overlay) {
            overlay.addEventListener('click', function () {
                sidebar.classList.remove('open');
                overlay.classList.remove('open');
                document.body.style.overflow = '';
            });
        }
    }

    /* ================================================================
       Lazy Loading for Property Card Images
       ================================================================ */
    function initLazyLoading() {
        var lazyImages = document.querySelectorAll('img[data-src]');
        if (lazyImages.length === 0) return;

        if ('IntersectionObserver' in window) {
            var imageObserver = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (entry.isIntersecting) {
                        var img = entry.target;
                        img.src = img.getAttribute('data-src');
                        img.removeAttribute('data-src');
                        img.classList.add('loaded');

                        // Handle load error with placeholder
                        img.addEventListener('error', function () {
                            img.src = '/assets/img/placeholder.jpg';
                            img.alt = 'Image not available';
                        });

                        imageObserver.unobserve(img);
                    }
                });
            }, {
                rootMargin: '100px 0px'
            });

            lazyImages.forEach(function (img) {
                imageObserver.observe(img);
            });
        } else {
            // Fallback: load all images immediately
            lazyImages.forEach(function (img) {
                img.src = img.getAttribute('data-src');
                img.removeAttribute('data-src');
            });
        }
    }

    /* ================================================================
       Smooth Scroll to Anchor Links
       ================================================================ */
    function initSmoothScroll() {
        document.addEventListener('click', function (e) {
            var link = e.target.closest('a[href^="#"]');
            if (!link) return;

            var targetId = link.getAttribute('href');
            if (targetId === '#' || targetId.length < 2) return;

            var target = document.querySelector(targetId);
            if (!target) return;

            e.preventDefault();

            var navbarHeight = 64; // Match .navbar-inner height
            var targetPosition = target.getBoundingClientRect().top + window.pageYOffset - navbarHeight;

            window.scrollTo({
                top: targetPosition,
                behavior: 'smooth'
            });

            // Update URL hash without jumping
            history.pushState(null, null, targetId);
        });
    }

    /* ================================================================
       Toast Notification System
       ================================================================ */

    /**
     * Show a toast notification.
     *
     * @param {string} message   - The message to display.
     * @param {string} type      - 'success', 'error', 'warning', or 'info'.
     * @param {number} duration  - How long to show (ms). Default 4000.
     */
    function showToast(message, type, duration) {
        type = type || 'info';
        duration = duration || 4000;

        // Ensure container exists
        var container = document.querySelector('.toast-container');
        if (!container) {
            container = document.createElement('div');
            container.className = 'toast-container';
            document.body.appendChild(container);
        }

        // Create toast element
        var toast = document.createElement('div');
        toast.className = 'toast toast-' + type;
        toast.innerHTML =
            '<span class="toast-message">' + escapeHtml(message) + '</span>' +
            '<button class="toast-close" aria-label="Close">&times;</button>';

        container.appendChild(toast);

        // Close button handler
        var closeBtn = toast.querySelector('.toast-close');
        closeBtn.addEventListener('click', function () {
            removeToast(toast);
        });

        // Auto-remove after duration
        var timer = setTimeout(function () {
            removeToast(toast);
        }, duration);

        // Pause timer on hover
        toast.addEventListener('mouseenter', function () {
            clearTimeout(timer);
        });

        toast.addEventListener('mouseleave', function () {
            timer = setTimeout(function () {
                removeToast(toast);
            }, 2000);
        });
    }

    /**
     * Remove a toast with animation.
     */
    function removeToast(toast) {
        if (!toast || toast.classList.contains('removing')) return;
        toast.classList.add('removing');
        setTimeout(function () {
            if (toast.parentNode) {
                toast.parentNode.removeChild(toast);
            }
        }, 300);
    }

    // Expose showToast globally
    window.showToast = showToast;

    /* ================================================================
       Copy-to-Clipboard for Schema Code Blocks
       ================================================================ */
    function initCopyToClipboard() {
        var codeBlocks = document.querySelectorAll('.code-block-wrapper');
        if (codeBlocks.length === 0) return;

        codeBlocks.forEach(function (wrapper) {
            var copyBtn = wrapper.querySelector('.copy-btn');
            var codeEl = wrapper.querySelector('pre code') || wrapper.querySelector('pre');

            if (!copyBtn || !codeEl) return;

            copyBtn.addEventListener('click', function () {
                var text = codeEl.textContent || codeEl.innerText;

                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text).then(function () {
                        showCopiedFeedback(copyBtn);
                    }).catch(function () {
                        fallbackCopy(text, copyBtn);
                    });
                } else {
                    fallbackCopy(text, copyBtn);
                }
            });
        });
    }

    /**
     * Fallback copy using a temporary textarea.
     */
    function fallbackCopy(text, btn) {
        var textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.position = 'fixed';
        textarea.style.left = '-9999px';
        textarea.style.top = '-9999px';
        document.body.appendChild(textarea);
        textarea.select();

        try {
            document.execCommand('copy');
            showCopiedFeedback(btn);
        } catch (err) {
            showToast('Failed to copy to clipboard.', 'error');
        }

        document.body.removeChild(textarea);
    }

    /**
     * Show visual feedback on the copy button.
     */
    function showCopiedFeedback(btn) {
        var originalText = btn.textContent;
        btn.textContent = 'Copied!';
        btn.classList.add('copied');

        setTimeout(function () {
            btn.textContent = originalText;
            btn.classList.remove('copied');
        }, 2000);
    }

    /* ================================================================
       Alert Dismiss Buttons
       ================================================================ */
    function initAlertDismiss() {
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.alert-dismiss');
            if (!btn) return;

            var alert = btn.closest('.alert');
            if (alert) {
                alert.style.transition = 'opacity 0.3s ease, max-height 0.3s ease';
                alert.style.opacity = '0';
                alert.style.maxHeight = alert.offsetHeight + 'px';
                setTimeout(function () {
                    alert.style.maxHeight = '0';
                    alert.style.padding = '0';
                    alert.style.margin = '0';
                    alert.style.overflow = 'hidden';
                }, 10);
                setTimeout(function () {
                    if (alert.parentNode) {
                        alert.parentNode.removeChild(alert);
                    }
                }, 350);
            }
        });
    }

    /* ================================================================
       Modal Open / Close
       ================================================================ */
    function initModals() {
        // Open modal
        document.addEventListener('click', function (e) {
            var trigger = e.target.closest('[data-modal]');
            if (!trigger) return;

            var modalId = trigger.getAttribute('data-modal');
            var modal = document.querySelector('#' + modalId);
            var backdrop = document.querySelector('#' + modalId + '-backdrop');

            if (modal) {
                modal.classList.add('open');
                if (backdrop) backdrop.classList.add('open');
                document.body.style.overflow = 'hidden';
            }
        });

        // Close modal via close button
        document.addEventListener('click', function (e) {
            var closeBtn = e.target.closest('.modal-close, [data-modal-close]');
            if (!closeBtn) return;

            var modal = closeBtn.closest('.modal');
            if (modal) {
                closeModal(modal);
            }
        });

        // Close modal via backdrop click
        document.addEventListener('click', function (e) {
            if (e.target.classList.contains('modal-backdrop') && e.target.classList.contains('open')) {
                var modalId = e.target.id.replace('-backdrop', '');
                var modal = document.querySelector('#' + modalId);
                if (modal) closeModal(modal);
                e.target.classList.remove('open');
                document.body.style.overflow = '';
            }
        });

        // Close modal on Escape key
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                var openModal = document.querySelector('.modal.open');
                if (openModal) {
                    closeModal(openModal);
                }
            }
        });
    }

    /**
     * Close a modal and its backdrop.
     */
    function closeModal(modal) {
        modal.classList.remove('open');
        var backdrop = document.querySelector('#' + modal.id + '-backdrop');
        if (backdrop) backdrop.classList.remove('open');
        document.body.style.overflow = '';
    }

    // Expose globally for programmatic use
    window.closeModal = closeModal;

    /* ================================================================
       Escape HTML (utility)
       ================================================================ */
    function escapeHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    /* ================================================================
       Initialise Everything on DOMContentLoaded
       ================================================================ */
    document.addEventListener('DOMContentLoaded', function () {
        initMobileNav();
        initSearchForm();
        initInlineSearch();
        initScoreGauges();
        initScoreBars();
        initFilterPanel();
        initEnquiryForm();
        initClaimForm();
        initBulkActions();
        initConfirmDialogs();
        initAdminSidebar();
        initLazyLoading();
        initSmoothScroll();
        initCopyToClipboard();
        initAlertDismiss();
        initModals();
    });

})();
