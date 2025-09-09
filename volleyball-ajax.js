/**
 * Volleyball League Tables - AJAX functionality for sub-league accordion
 */

jQuery(document).ready(function($) {
    'use strict';

    console.log('=== VOLLEYBALL AJAX DEBUG ===');
    console.log('Volleyball AJAX script loaded successfully');
    console.log('jQuery available:', typeof $ !== 'undefined');
    console.log('jQuery version:', $.fn.jquery);
    console.log('volleyball_ajax object:', typeof volleyball_ajax !== 'undefined' ? volleyball_ajax : 'NOT FOUND');

    // Test if we can find accordion elements
    console.log('Accordion headers found:', $('.accordion-header').length);
    console.log('Volleyball accordions found:', $('.volleyball-accordion').length);
    
    // Test REST API endpoint directly
    if (typeof volleyball_ajax !== 'undefined' && volleyball_ajax.rest_url) {
        console.log('Testing REST API endpoint...');
        $.ajax({
            url: volleyball_ajax.rest_url,
            type: 'GET',
            dataType: 'json',
            success: function(response) {
                console.log('REST API test successful. Available leagues:', response);
            },
            error: function(xhr, status, error) {
                console.error('REST API test failed:', xhr.status, error);
                console.error('Response text:', xhr.responseText);
            }
        });
    }

    // Auto-load first sub-league for nested leagues
    function autoLoadFirstSubleague() {
        console.log('Checking for accordions to auto-load...');
        $('.volleyball-accordion').each(function() {
            var $accordion = $(this);
            var $autoLoadButton = $accordion.find('.accordion-header[data-autoload="true"]');

            console.log('Found accordion for league:', $accordion.data('league'));
            console.log('Auto-load button found:', $autoLoadButton.length);

            if ($autoLoadButton.length > 0) {
                console.log('Auto-loading first sub-league for accordion:', $accordion.data('league'));
                console.log('Button data-subleague:', $autoLoadButton.data('subleague'));

                // Directly load the first sub-league instead of triggering click
                var league = $accordion.data('league');
                var subleague = $autoLoadButton.data('subleague');
                var $content = $accordion.find('.accordion-content');
                var $tableContainer = $content.find('#subleague-table-container');

                console.log('Direct loading subleague:', subleague, 'for league:', league);

                // Remove active class from all buttons
                $accordion.find('.accordion-header').removeClass('active');

                // Add active class to the first button
                $autoLoadButton.addClass('active');

                // Show loading state
                $content.find('.volleyball-loading').show();
                $tableContainer.hide();

                // Make AJAX request directly
                var ajaxUrl = volleyball_ajax.rest_url + encodeURIComponent(league) + '/' + encodeURIComponent(subleague);
                console.log('Direct AJAX URL:', ajaxUrl);

                // Load the data directly
                loadSubleagueData(league, subleague, $content, $tableContainer, $accordion);
            } else {
                console.log('No auto-load button found for accordion:', $accordion.data('league'));
            }
        });
    }

    // Extract the data loading logic into a separate function
    function loadSubleagueData(league, subleague, $content, $tableContainer, $accordion) {
        var ajaxUrl = volleyball_ajax.rest_url + encodeURIComponent(league) + '/' + encodeURIComponent(subleague);
        console.log('Loading data from:', ajaxUrl);

        $.ajax({
            url: ajaxUrl,
            type: 'GET',
            dataType: 'json',
            success: function(response) {
                console.log('Direct AJAX Success for subleague:', subleague, response);

                if (!response || !Array.isArray(response) || response.length === 0) {
                    console.log('No teams found, trying fallback...');
                    // Fallback: Get all teams for the league and filter by subleague
                    var leagueUrl = volleyball_ajax.rest_url + encodeURIComponent(league);
                    console.log('Fallback URL:', leagueUrl);

                    $.ajax({
                        url: leagueUrl,
                        type: 'GET',
                        dataType: 'json',
                        success: function(response) {
                            console.log('Fallback success, filtering for subleague:', subleague);
                            var filteredTeams = filterTeamsBySubleague(response, subleague);

                            if (filteredTeams.length > 0) {
                                var tableHtml = generateTeamTableHtml(filteredTeams, league, subleague);
                                $content.find('.volleyball-loading').hide();
                                $tableContainer.html(tableHtml).show();
                            } else {
                                $content.find('.volleyball-loading').hide();
                                $tableContainer.html('<div class="volleyball-error">No teams found for ' + subleague + '</div>').show();
                            }
                        },
                        error: function(xhr, status, error) {
                            console.error('Fallback error:', error);
                            $content.find('.volleyball-loading').hide();
                            $tableContainer.html('<div class="volleyball-error">Error loading data</div>').show();
                        }
                    });
                } else {
                    // Direct response worked
                    var tableHtml = generateTeamTableHtml(response, league, subleague);
                    $content.find('.volleyball-loading').hide();
                    $tableContainer.html(tableHtml).show();
                }
            },
            error: function(xhr, status, error) {
                console.error('Direct AJAX error:', error);
                // Try fallback
                var leagueUrl = volleyball_ajax.rest_url + encodeURIComponent(league);
                $.ajax({
                    url: leagueUrl,
                    type: 'GET',
                    dataType: 'json',
                    success: function(response) {
                        var filteredTeams = filterTeamsBySubleague(response, subleague);
                        if (filteredTeams.length > 0) {
                            var tableHtml = generateTeamTableHtml(filteredTeams, league, subleague);
                            $content.find('.volleyball-loading').hide();
                            $tableContainer.html(tableHtml).show();
                        } else {
                            $content.find('.volleyball-loading').hide();
                            $tableContainer.html('<div class="volleyball-error">No teams found for ' + subleague + '</div>').show();
                        }
                    },
                    error: function(xhr, status, error) {
                        $content.find('.volleyball-loading').hide();
                        $tableContainer.html('<div class="volleyball-error">Error loading data</div>').show();
                    }
                });
            }
        });
    }

    // Helper function to filter teams by subleague
    function filterTeamsBySubleague(response, subleague) {
        var allTeams = [];

        if (Array.isArray(response)) {
            allTeams = response;
        } else if (typeof response === 'object' && response !== null) {
            if (Array.isArray(response.teams)) {
                allTeams = response.teams;
            } else if (Array.isArray(response.subLeagues)) {
                response.subLeagues.forEach(function(subLeagueObj) {
                    if (subLeagueObj.name === subleague && Array.isArray(subLeagueObj.teams)) {
                        allTeams = subLeagueObj.teams;
                        allTeams.forEach(function(team) {
                            team.subleague = subLeagueObj.name;
                        });
                    }
                });
            }
        }

        return allTeams.filter(function(team) {
            return team.subleague === subleague || team.subleague === subleague.toLowerCase();
        });
    }

    // Try to auto-load immediately
    autoLoadFirstSubleague();

    // Also try after a longer delay in case the content loads slowly
    setTimeout(autoLoadFirstSubleague, 1000);

    // Use mutation observer as a fallback
    if (typeof MutationObserver !== 'undefined') {
        var observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                if (mutation.type === 'childList' && mutation.addedNodes.length > 0) {
                    var hasNewAccordion = false;
                    mutation.addedNodes.forEach(function(node) {
                        if (node.nodeType === 1) {
                            if ($(node).hasClass('volleyball-accordion') || $(node).find('.volleyball-accordion').length > 0) {
                                hasNewAccordion = true;
                            }
                        }
                    });

                    if (hasNewAccordion) {
                        console.log('New accordion detected, trying auto-load');
                        setTimeout(autoLoadFirstSubleague, 200);
                    }
                }
            });
        });

        observer.observe(document.body, {
            childList: true,
            subtree: true
        });
    }

    // Handle accordion header clicks
    $(document).on('click', '.accordion-header', function(e) {
        e.preventDefault();

        console.log('Accordion header clicked');

        var $button = $(this);
        var $accordion = $button.closest('.volleyball-accordion');
        var $content = $accordion.find('.accordion-content');
        var $tableContainer = $content.find('#subleague-table-container');

        var league = $accordion.data('league');
        var subleague = $button.data('subleague');

        console.log('League:', league, 'Subleague:', subleague);
        console.log('REST URL:', volleyball_ajax.rest_url);

        // Remove active class from all buttons
        $accordion.find('.accordion-header').removeClass('active');

        // Add active class to clicked button
        $button.addClass('active');

        // Show loading state
        $content.find('.volleyball-loading').show();
        $tableContainer.hide();

        // Load data directly instead of using complex AJAX logic
        loadSubleagueData(league, subleague, $content, $tableContainer, $accordion);
    });

    /**
     * Generate HTML for team table
     */
    function generateTeamTableHtml(teams, league, subleague) {
        var html = '<div class="volleyball-table-container">';
        html += '<h3>' + escapeHtml(league) + ' - ' + escapeHtml(subleague) + '</h3>';
        html += '<div class="table-responsive">';
        html += '<table class="volleyball-league-table">';
        html += '<thead>';
        html += '<tr>';
        html += '<th rowspan="2" class="position-col">Pos</th>';
        html += '<th rowspan="2" class="team-col">Team</th>';
        html += '<th rowspan="2" class="points-col">Points</th>';
        html += '<th colspan="3" class="header-group">Matches</th>';
        html += '<th colspan="3" class="header-group">Sets</th>';
        html += '<th colspan="3" class="header-group">Points</th>';
        html += '<th colspan="6" class="header-group">Results</th>';
        html += '<th rowspan="2" class="ratio-col">Set Ratio</th>';
        html += '<th rowspan="2" class="ratio-col">Point Ratio</th>';
        html += '<th rowspan="2" class="penalty-col">Penalty</th>';
        html += '</tr>';
        html += '<tr>';
        html += '<th class="stats-col">Played</th>';
        html += '<th class="stats-col">Won</th>';
        html += '<th class="stats-col">Lost</th>';
        html += '<th class="stats-col">Won</th>';
        html += '<th class="stats-col">Lost</th>';
        html += '<th class="stats-col">Ratio</th>';
        html += '<th class="stats-col">Won</th>';
        html += '<th class="stats-col">Lost</th>';
        html += '<th class="stats-col">Ratio</th>';
        html += '<th class="stats-col">3-0</th>';
        html += '<th class="stats-col">3-1</th>';
        html += '<th class="stats-col">3-2</th>';
        html += '<th class="stats-col">2-3</th>';
        html += '<th class="stats-col">1-3</th>';
        html += '<th class="stats-col">0-3</th>';
        html += '</tr>';
        html += '</thead>';
        html += '<tbody>';

        teams.forEach(function(team) {
            // Safely decode JSON fields
            var matchStats = safeJsonDecode(team.match_stats);
            var setStats = safeJsonDecode(team.set_stats);
            var pointStats = safeJsonDecode(team.point_stats);
            var resultBreakdown = safeJsonDecode(team.result_breakdown);

            html += '<tr>';
            html += '<td class="position-col">' + escapeHtml(team.position || 0) + '</td>';
            html += '<td class="team-name">';
            if (team.logo_url) {
                html += '<img src="' + escapeHtml(team.logo_url) + '" alt="' + escapeHtml(team.team_name) + '" class="team-logo">';
            }
            html += escapeHtml(team.team_name);
            html += '</td>';
            html += '<td class="points-col">' + escapeHtml(team.ranking_points || 0) + '</td>';

            // Matches
            html += '<td class="stats-col">' + escapeHtml(matchStats.played || 0) + '</td>';
            html += '<td class="stats-col">' + escapeHtml(matchStats.won || 0) + '</td>';
            html += '<td class="stats-col">' + escapeHtml(matchStats.lost || 0) + '</td>';

            // Sets
            html += '<td class="stats-col">' + escapeHtml(setStats.won || 0) + '</td>';
            html += '<td class="stats-col">' + escapeHtml(setStats.lost || 0) + '</td>';
            html += '<td class="stats-col">' + (setStats.ratio ? parseFloat(setStats.ratio).toFixed(3) : '0.000') + '</td>';

            // Points
            html += '<td class="stats-col">' + escapeHtml(pointStats.won || 0) + '</td>';
            html += '<td class="stats-col">' + escapeHtml(pointStats.lost || 0) + '</td>';
            html += '<td class="stats-col">' + (pointStats.ratio ? parseFloat(pointStats.ratio).toFixed(3) : '0.000') + '</td>';

            // Results Breakdown
            html += '<td class="stats-col">' + escapeHtml(resultBreakdown.wins3_0 || 0) + '</td>';
            html += '<td class="stats-col">' + escapeHtml(resultBreakdown.wins3_1 || 0) + '</td>';
            html += '<td class="stats-col">' + escapeHtml(resultBreakdown.wins3_2 || 0) + '</td>';
            html += '<td class="stats-col">' + escapeHtml(resultBreakdown.losses2_3 || 0) + '</td>';
            html += '<td class="stats-col">' + escapeHtml(resultBreakdown.losses1_3 || 0) + '</td>';
            html += '<td class="stats-col">' + escapeHtml(resultBreakdown.losses0_3 || 0) + '</td>';

            // Ratios
            html += '<td class="ratio-col">' + (setStats.ratio ? parseFloat(setStats.ratio).toFixed(3) : '0.000') + '</td>';
            html += '<td class="ratio-col">' + (pointStats.ratio ? parseFloat(pointStats.ratio).toFixed(3) : '0.000') + '</td>';

            // Penalty
            html += '<td class="penalty-col">' + escapeHtml(team.penalty || '-') + '</td>';
            html += '</tr>';
        });

        html += '</tbody>';
        html += '</table>';
        html += '</div>';
        html += '</div>';

        return html;
    }

    /**
     * Safely decode JSON data
     */
    function safeJsonDecode(data) {
        if (typeof data === 'object' && data !== null) {
            return data;
        }

        if (typeof data === 'string') {
            try {
                return JSON.parse(data);
            } catch (e) {
                return {};
            }
        }

        return {};
    }

    /**
     * Escape HTML to prevent XSS
     */
    function escapeHtml(text) {
        if (text == null) return '';
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
});