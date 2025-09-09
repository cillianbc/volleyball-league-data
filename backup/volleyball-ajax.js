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

        // Make AJAX request to get sub-league data
        var ajaxUrl = volleyball_ajax.rest_url + encodeURIComponent(league) + '/' + encodeURIComponent(subleague);
        console.log('AJAX URL:', ajaxUrl);

        // First try to get data from the specific subleague endpoint
        $.ajax({
            url: ajaxUrl,
            type: 'GET',
            dataType: 'json',
            success: function(response) {
                console.log('AJAX Success for specific subleague:', response);
                
                // Check if response is empty or not an array
                if (!response || !Array.isArray(response) || response.length === 0) {
                    console.log('No teams found for specific subleague endpoint, trying fallback...');
                    // Fallback: Get all teams for the league and filter by subleague
                    var leagueUrl = volleyball_ajax.rest_url + encodeURIComponent(league);
                    console.log('Fallback AJAX URL:', leagueUrl);
                    
                    $.ajax({
                        url: leagueUrl,
                        type: 'GET',
                        dataType: 'json',
                        success: function(response) {
                            console.log('Fallback AJAX Success:', response);
                            console.log('Looking for teams with subleague:', subleague);
                            
                            // Check if response is in a different format (might be an object with nested structure)
                            var allTeams = [];
                            
                            if (Array.isArray(response)) {
                                // Standard array of teams
                                allTeams = response;
                                console.log('Response is a standard array of teams');
                            } else if (typeof response === 'object' && response !== null) {
                                console.log('Response is an object, checking for nested structure');
                                
                                // Check if it has a teams array
                                if (Array.isArray(response.teams)) {
                                    allTeams = response.teams;
                                    console.log('Found teams array in response');
                                }
                                
                                // Check if it has subLeagues array
                                if (Array.isArray(response.subLeagues)) {
                                    console.log('Found subLeagues array in response');
                                    response.subLeagues.forEach(function(subLeagueObj) {
                                        if (subLeagueObj.name === subleague && Array.isArray(subLeagueObj.teams)) {
                                            console.log('Found matching subLeague with teams:', subLeagueObj.name);
                                            // Use these teams directly since they're already for the right subleague
                                            allTeams = subLeagueObj.teams;
                                            // Add subleague field to each team for consistency
                                            allTeams.forEach(function(team) {
                                                team.subleague = subLeagueObj.name;
                                            });
                                        }
                                    });
                                }
                            }
                            
                            console.log('Processed allTeams:', allTeams);
                            
                            // Filter teams by subleague
                            var filteredTeams = [];
                            if (allTeams && allTeams.length > 0) {
                                console.log('Total teams received:', allTeams.length);
                                
                                // Log all subleagues found in the data
                                var subleaguesFound = [];
                                allTeams.forEach(function(team) {
                                    if (team.subleague && subleaguesFound.indexOf(team.subleague) === -1) {
                                        subleaguesFound.push(team.subleague);
                                    }
                                });
                                console.log('Subleagues found in data:', subleaguesFound);
                                
                                // Try case-insensitive matching as a fallback
                                filteredTeams = allTeams.filter(function(team) {
                                    // First try exact match
                                    if (team.subleague === subleague) {
                                        return true;
                                    }
                                    
                                    // Then try case-insensitive match
                                    if (team.subleague && team.subleague.toLowerCase() === subleague.toLowerCase()) {
                                        console.log('Found case-insensitive match:', team.subleague);
                                        return true;
                                    }
                                    
                                    return false;
                                });
                                console.log('Filtered teams for ' + subleague + ':', filteredTeams);
                            } else {
                                console.log('No teams received from fallback request');
                            }
                            
                            if (filteredTeams.length > 0) {
                                // Generate HTML for the table
                                var tableHtml = generateTeamTableHtml(filteredTeams, league, subleague);
                                
                                // Hide loading and show table
                                $content.find('.volleyball-loading').hide();
                                $tableContainer.html(tableHtml).show();
                            } else {
                                $content.find('.volleyball-loading').hide();
                                $tableContainer.html('<div class="volleyball-error">No teams found for ' + subleague + '</div>').show();
                            }
                        },
                        error: function(xhr, status, error) {
                            console.error('Fallback AJAX Error:', xhr.status, error);
                            console.error('Response text:', xhr.responseText);
                            
                            // Try to parse the response text if it's JSON
                            try {
                                var errorObj = JSON.parse(xhr.responseText);
                                console.error('Parsed error response:', errorObj);
                            } catch (e) {
                                console.error('Could not parse error response as JSON');
                            }
                            
                            $content.find('.volleyball-loading').hide();
                            $tableContainer.html('<div class="volleyball-error">' +
                                '<p>Error loading ' + league + ' data. Status: ' + xhr.status + '</p>' +
                                '<p>Error message: ' + error + '</p>' +
                                '<p>Please check the browser console for more details.</p>' +
                                '</div>').show();
                        }
                    });
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX Error:', xhr.status, error);
                console.error('Response text:', xhr.responseText);
                
                // Try to parse the response text if it's JSON
                try {
                    var errorObj = JSON.parse(xhr.responseText);
                    console.error('Parsed error response:', errorObj);
                } catch (e) {
                    console.error('Could not parse error response as JSON');
                }
                
                // Try fallback anyway
                console.log('Error occurred, trying fallback...');
                // Fallback: Get all teams for the league and filter by subleague
                var leagueUrl = volleyball_ajax.rest_url + encodeURIComponent(league);
                console.log('Fallback AJAX URL after error:', leagueUrl);
                
                $.ajax({
                    url: leagueUrl,
                    type: 'GET',
                    dataType: 'json',
                    success: function(response) {
                        // Same success handler as in the normal fallback
                        console.log('Fallback AJAX Success after error:', response);
                        console.log('Looking for teams with subleague:', subleague);
                        
                        // Check if response is in a different format (might be an object with nested structure)
                        var allTeams = [];
                        
                        if (Array.isArray(response)) {
                            // Standard array of teams
                            allTeams = response;
                            console.log('Response is a standard array of teams');
                        } else if (typeof response === 'object' && response !== null) {
                            console.log('Response is an object, checking for nested structure');
                            
                            // Check if it has a teams array
                            if (Array.isArray(response.teams)) {
                                allTeams = response.teams;
                                console.log('Found teams array in response');
                            }
                            
                            // Check if it has subLeagues array
                            if (Array.isArray(response.subLeagues)) {
                                console.log('Found subLeagues array in response');
                                response.subLeagues.forEach(function(subLeagueObj) {
                                    if (subLeagueObj.name === subleague && Array.isArray(subLeagueObj.teams)) {
                                        console.log('Found matching subLeague with teams:', subLeagueObj.name);
                                        // Use these teams directly since they're already for the right subleague
                                        allTeams = subLeagueObj.teams;
                                        // Add subleague field to each team for consistency
                                        allTeams.forEach(function(team) {
                                            team.subleague = subLeagueObj.name;
                                        });
                                    }
                                });
                            }
                        }
                        
                        console.log('Processed allTeams:', allTeams);
                        
                        // Filter teams by subleague
                        var filteredTeams = [];
                        if (allTeams && allTeams.length > 0) {
                            console.log('Total teams received:', allTeams.length);
                            
                            // Log all subleagues found in the data
                            var subleaguesFound = [];
                            allTeams.forEach(function(team) {
                                if (team.subleague && subleaguesFound.indexOf(team.subleague) === -1) {
                                    subleaguesFound.push(team.subleague);
                                }
                            });
                            console.log('Subleagues found in data:', subleaguesFound);
                            
                            // Try case-insensitive matching as a fallback
                            filteredTeams = allTeams.filter(function(team) {
                                // First try exact match
                                if (team.subleague === subleague) {
                                    return true;
                                }
                                
                                // Then try case-insensitive match
                                if (team.subleague && team.subleague.toLowerCase() === subleague.toLowerCase()) {
                                    console.log('Found case-insensitive match:', team.subleague);
                                    return true;
                                }
                                
                                return false;
                            });
                            console.log('Filtered teams for ' + subleague + ':', filteredTeams);
                        } else {
                            console.log('No teams received from fallback request');
                        }
                        
                        if (filteredTeams.length > 0) {
                            // Generate HTML for the table
                            var tableHtml = generateTeamTableHtml(filteredTeams, league, subleague);
                            
                            // Hide loading and show table
                            $content.find('.volleyball-loading').hide();
                            $tableContainer.html(tableHtml).show();
                        } else {
                            $content.find('.volleyball-loading').hide();
                            $tableContainer.html('<div class="volleyball-error">No teams found for ' + subleague + '</div>').show();
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Fallback AJAX Error after initial error:', xhr.status, error);
                        console.error('Response text:', xhr.responseText);
                        
                        $content.find('.volleyball-loading').hide();
                        $tableContainer.html('<div class="volleyball-error">' +
                            '<p>Error loading ' + league + ' data. Status: ' + xhr.status + '</p>' +
                            '<p>Error message: ' + error + '</p>' +
                            '<p>Please check the browser console for more details.</p>' +
                            '</div>').show();
                    }
                });
            }
        });
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