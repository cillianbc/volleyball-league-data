
    name: "Division 1 Men",
    id: 44,

    name: "Premier Men",
    id: 43

    name: "Premier Women",
    id: 49

    name: "Division 1 Women",
    id: 50

    name: "Division 3 Men",
    id: 46

    name: "Division 2 Men",
    id: 45
 
    name: "Division 3 Women",
    id: 52

    name: "Division 2 Women",
    id: 51,



The league results tables live on this URL structure, where ID is from one of the corrsponding leagues above
https://vir-web.dataproject.com/CompetitionStandings.aspx?ID=

The fixtures and results are on this URL structure, where ID is from one of the corrsponding leagues above
https://vir-web.dataproject.com/CompetitionMatches.aspx?ID=


Match Results page is structured like the below URL
Where mID is the match ID which can be gotten from the OnClick in <p> tag that houses the result on the CompetitionMatches.aspx page

https://vir-web.dataproject.com/MatchStatistics.aspx?mID=885&ID=24&type=LegList
    On this page there is a <div id="DIV_EscorerSheetPdf"> that contains a link to the scoresheet which we want to get also 

Where mID is the match ID which can be gotten from the OnClick in <p> tag that houses the result on the CompetitionMatches.aspx page
