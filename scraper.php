<?

//
// // Write out to the sqlite database using scraperwiki library
// scraperwiki::save_sqlite(array('name'), array('name' => 'susan', 'occupation' => 'software developer'));
//
// // An arbitrary query against the database
// scraperwiki::select("* from data where 'name'='peter'")

// You don't have to do things with the ScraperWiki library. You can use whatever is installed
// on Morph for PHP (See https://github.com/openaustralia/morph-docker-php) and all that matters
// is that your final data is written to an Sqlite database called data.sqlite in the current working directory which
// has at least a table called data.


require 'rb.php';
require 'simple_html_dom.php';  
R::setup('sqlite:data.sqlite');

R::nuke();


$topics = array('php','angularjs','magento','zend-framework2','symfony2','java','ember.js','reactjs');

//gather new questions?
foreach ($topics as $topic)
{
    //get all questions, if we have any, skip this (may need to nuke and rerun if not all captured on first run)
    for($i=1; $i<=20; $i++) {
        echo "\ngetting page $topic page $i "; 
        $url = "http://stackoverflow.com/questions/tagged/".$topic."?page=".$i."&sort=votes&pagesize=50";
        
        //only capture toc pages once
        $result = R::findOne( 'toc', ' url = ? ', array( $url ) );
        //if the result is not null that means its already in the db so continue on to the next one in this loop
        if(!is_null($result)) { echo " -- already got"; continue; }
        
        $html = file_get_contents($url);
        $dom = new simple_html_dom();
        $dom->load($html);
        foreach($dom->find('a[class=question-hyperlink]') as $data ){
            
            echo "\n scraping question: " . $data->href;
            
            //only capture questions once
            $result = R::findOne( 'questions', ' url = ? ', array( $data->href ) );
            //if the result is not null that means its already in the db so continue on to the next one in this loop
            if(!is_null($result)) { echo " -- already got"; continue; }
            
            $questions = R::dispense('questions');
            $questions->url=$data->href;
            preg_match("/\/questions\/(?<id>[0-9]*)\//",$questions['url'],$matches);
            $questions->qid = $matches[1];
            $questions->scraped=0;
            R::store($questions); 
            addUsersToList(); //I decided to get everything in coalated fashion per question -- instead of all questions, all user lists, all users, all geocode... 
            getUsers();
            geocodeUsers();
        }
        $toc = R::dispense('toc');
        $toc->url = $url; 
        R::store($toc);
        $dom->clear();
        unset($html,$questions,$data,$matches,$result);
    }
}

function addUsersToList() {
    
    $questions = R::getAll('select * from questions where scraped != 1 order by id desc');
    
    //go throught he questions and capture users, this will just bypass all if all have been scrapped 
    foreach($questions as $question){
        
        if( $question['scraped'] == 1 ) continue; //redundant but whatever.
        
        $url = "http://stackoverflow.com".$question['url'];
        $html = file_get_contents($url);
        $dom = new simple_html_dom();
        $dom->load($html);
        
        //get all the users boxes from main answerers
        foreach($dom->find('div.user-details a') as $data )
        {
            if( strpos($data->href,"/users/")!==FALSE)
            {
            
            echo "\nSaving user to list ".$data->innertext;
            
            preg_match("/\/users\/(?<id>[0-9]*)/",$data->href,$matches);
            
            if(!isset($matches[1])) { echo " -- no id"; continue; }
            
            //only capture users once
            $result = R::findOne( 'data', ' uid = ? ', array( $matches[1] ) );
            //if the result is not null that means its already in the db so continue on to the next one in this loop
            if(!is_null($result)) { echo " -- already got"; continue; }
            
            $users = R::dispense('data');
            $users->url=$data->href;
            $users->uid = $matches[1];
            $users->name = $data->innertext;
            R::store($users); 
            }

        }
        
        //get all the comment user links
        foreach($dom->find('a.comment-user') as $data ){
            if( strpos($data->href,"/users/")!==FALSE)
            {
            
            echo "\nSaving user to list ".$data->innertext;
            
            preg_match("/\/users\/(?<id>[0-9]*)\//",$data->href,$matches);
            
            if(!isset($matches[1])) { echo " -- no id"; continue; }
            
            //only capture users once
            $result = R::findOne( 'users', ' uid = ? ', array( $matches[1] ) );
            //if the result is not null that means its already in the db so continue on to the next one in this loop
            if(!is_null($result)) { echo " -- already got"; continue; }
            
            $users = R::dispense('data');
            $users->url=$data->href;
            $users->uid = $matches[1];
            $users->name = $data->innertext;
            R::store($users); 
            }
        }
        
        //update saying scrapped 
        $_question = R::load('questions',$question['id']);
        $_question->scraped = 1; 
        R::store($_question);
    
        if(isset($dom)) $dom->clear();
        unset($html,$users,$_question,$result,$matches,$question,$questions);
    }
}

/*
//had a few dupe users - decided to cleanup rather than hammer SO site again.
$users = R::getAll('select * from users group by uid having count(*) > 1'); 
foreach($users as $user) { $_user = R::load('users',$user['id']);  R::trash( $_user );   }
*/

function getUsers() {
    
    //Third go through each user page and capture their info!
    $users = R::getAll('select * from data');
    
    foreach($users as $user){
        
        echo "\nGetting ".$user['name'];
        
        //get out of here if there's no url or it's a generic user
        if ( $user['url'] == '' ) {echo "empty url\n"; continue; }
        if ( $user['name'] == 'Community' ) {echo "generic user\n"; continue; }
        
        //skip already scraped
        if( isset( $user['scraped'] ))
        if( $user['scraped'] == 1) {echo " -- already got"; continue; }
        
        $url = "http://stackoverflow.com".$user['url'];
        $html = file_get_contents($url);
        $dom = new simple_html_dom();
        $dom->load($html);
        
        //problem with the retrieval?
        if ( ! method_exists($dom,"find") ) continue;
        if ( ! $dom->find('html') ) continue;
        //is the url wrong? account deleted?
        if ( strpos( $dom->plaintext,'Page Not Found' ) !== FALSE ) { echo "page not found\n"; continue; }
        
        $_users = R::load('data',$user['id']);
        
        //$_users->name = $dom->find("h1[id=user-displayname]",0)->innertext;
        $_users->location = $dom->find("td[class=adr]",0)->innertext;
        foreach($dom->find('div[class=data]',0)->find('table',0)->find('td') as $data ) {
            if ( $data->innertext == "age" ) $_users->age = $data->next_sibling()->innertext;
            if ( $data->innertext == "profile views" ) $_users->views = $data->next_sibling()->innertext;
        }
        if( $dom->find('a[class=url]',0) ) $_users->website = $dom->find('a[class=url]',0)->href;
        $_users->member_since = $dom->find('td[class=cool]',0)->innertext;
        $_users->last_seen = $dom->find('span[class=relativetime]',0)->innertext;
        $_users->about = $dom->find('div[class=user-about-me]',0)->innertext;
        $_users->logo = $dom->find('img[class=logo]',0)->src;
        $_users->reputation = $dom->find('div[class=reputation]',0)->find('span',0)->find('a',0)->innertext;
        //counts questions, badges etc
        foreach($dom->find('div[class=subheader] h1 a[href*=tab]') as $data ) {
            $key = trim( substr( strrchr($data->href,'='), 1 ) );
            if($key)
                $_users->$key = $data->find('span',0)->innertext;
        }
        //this is for tags. it will blow up a table horizontally so we need to link to another vertical table
        foreach($dom->find('div[class=answer-votes]') as $data ) {
            $tags = R::dispense('tags');
            $value = $data->innertext;
            $tag = preg_replace( '/[^a-z]/i', '', $data->next_sibling()->innertext );
            $tags->tag = $tag;
            $tags->value = $value;
            $tags->uid = $user['uid'];
            R::store($tags);
        }
        
        $_users->scraped = 1;
        
        $_users->lat = '';
        $_users->lng = '';
        
        R::store($_users);
    }
    
    if(isset($dom)) $dom->clear();
    unset($html,$tags,$_users,$result,$users,$user);

}

function geocodeUsers () {
    
    $users = R::getAll('select * from data');
    
    foreach($users as $user){
        if($user['lat'] != '') continue; //if we already did, skip
        if($user['lng'] != '') continue; 
        if($user['location']=='') continue; 
        
        $addr = urlencode($user['location']);
        $addr = str_replace("%2C","",$addr);
        $url = 'http://maps.googleapis.com/maps/api/geocode/json?sensor=false&address='.$addr;
        $get = file_get_contents($url);
        $records = json_decode($get,TRUE);
        
        echo $addr.":";
        if ( $records['status'] == 'OK' ) {
            //neat_r($records['results'][]);
            $lat = $records['results'][0]['geometry']['location']['lat'];
            $lng = $records['results'][0]['geometry']['location']['lng'];
            echo $lat."-".$lng."\n";
            $_users = R::load('data',$user['id']);
            $_users->lat = $lat; 
            $_users->lng = $lng; 
            R::store($_users); 
        }else{
            echo "N/A\n";
            $_users = R::load('data',$user['id']);
            $_users->lat = 'XXX'; 
            $_users->lng = 'XXX'; 
            R::store($_users); 
        }
    } 
}

function neat_r($arr, $return = false) {
    $out = array();
    $oldtab = " ";
    $newtab = "-";
    $lines = explode("\n", print_r($arr, true));
    foreach ($lines as $line) {
    //remove numeric indexes like "[0] =>" unless the value is an array
    if (substr($line, -5) != "Array") { $line = preg_replace("/^(\s*)\[[0-9]+\] => /", "$1", $line, 1); }
    //garbage symbols
    foreach (array(
    "Array" => "",
    "[" => "",
    "]" => "",
    " =>" => ":",
    ) as $old => $new) {
    $out = str_replace($old, $new, $out);
    }
    //garbage lines
    if (in_array(trim($line), array("Array", "(", ")", ""))) continue;
    //indents
    $indent = "";
    $indents = floor((substr_count($line, $oldtab) - 1) / 2);
    if ($indents > 0) { for ($i = 0; $i < $indents; $i++) { $indent .= $newtab; } }
    $out[] = $indent . trim($line);
    }
    $out = implode("\n", $out) . "\n";
    if ($return == true) return $out;
    echo $out;
}



?>
