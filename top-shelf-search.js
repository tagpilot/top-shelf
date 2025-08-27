(function($) {
    'use strict';

    // Cache DOM elements
    const $searchIcon = $('.top-shelf-search-toggle');
    const $searchOverlay = $('.top-shelf-search-overlay');
    const $searchInput = $('.top-shelf-search-input');
    const $searchClose = $('.top-shelf-search-close');
    const $searchResults = $('.top-shelf-search-results');
    const $searchContainer = $('.top-shelf-search-container');

    // Debounce function
    function debounce(func, wait) {
        let timeout;
        return function(...args) {
            clearTimeout(timeout);
            timeout = setTimeout(() => func.apply(this, args), wait);
        };
    }

    // Toggle search overlay
    $searchIcon.on('click', function(e) {
        e.preventDefault();
        $searchOverlay.addClass('active');
        $searchInput.focus();
    });

    // Close search overlay
    function closeSearch() {
        $searchOverlay.removeClass('active');
        $searchInput.val('');
        $searchResults.removeClass('active').empty();
    }

    $searchClose.on('click', closeSearch);

    // Close on escape key
    $(document).on('keyup', function(e) {
        if (e.key === 'Escape') {
            closeSearch();
        }
    });

    // Close on click outside
    $searchOverlay.on('click', function(e) {
        if (!$(e.target).closest($searchContainer).length) {
            closeSearch();
        }
    });

    // Prevent clicks inside container from closing
    $searchContainer.on('click', function(e) {
        e.stopPropagation();
    });

    // Handle search input
    $searchInput.on('input', debounce(function() {
        const searchTerm = $(this).val();

        if (searchTerm.length < 2) {
            $searchResults.removeClass('active').empty();
            return;
        }

        // Make REST API request
        $.ajax({
            url: topShelfSearch.restUrl,
            method: 'GET',
            data: {
                term: searchTerm
            },
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', topShelfSearch.nonce);
            }
        })
        .done(function(response) {
            displayResults(response);
        })
        .fail(function(xhr, status, error) {
            console.error('Search failed:', error);
        });
    }, 300));

    function displayResults(results) {
        $searchResults.empty();

        if (!results.length) {
            $searchResults.html('<p>No products found</p>');
        } else {
            const resultHtml = results.map(product => `
                <a href="${product.permalink}" class="search-result-item">
                    <img src="${product.thumbnail || 'placeholder-image-url.jpg'}"
                         class="search-result-thumbnail"
                         alt="${product.title}">
                    <div class="search-result-content">
                        <div class="search-result-title">${product.title}</div>
                        <div class="search-result-price-row">
                            <div class="search-result-price">${product.price}</div>
                            <div class="search-result-stock ${product.is_in_stock ? 'in-stock' : 'out-of-stock'}">
                                ${product.stock_status}
                            </div>
                        </div>
                    </div>
                </a>
            `).join('');

            $searchResults.html(resultHtml);
        }

        $searchResults.addClass('active');
    }

})(jQuery);