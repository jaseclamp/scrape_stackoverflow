<?

require 'rb.php';

R::setup('sqlite:data.sqlite');

if( isset( $_SERVER['MORPH_NUKE'] ) )
	if ( $_SERVER['MORPH_NUKE'] == 'Y' ) 
		R::nuke();

//get all SO users by create date ascending
//pick up where you left off in the pagination index 

$max = R::getCell('select max(page) from data');
if(!$max) $max=1; 

echo "\n got up to page ".$max." last time";
echo "\n";

for($i=$max; $i<=$max+1000; $i++)
{
	
	echo $i . ', ';
	
	$json = file_get_contents( 'https://api.stackexchange.com/2.2/users?page='.$i.'&pagesize=100&order=asc&sort=creation&site=stackoverflow' );
	
	$users = json_decode($json,true);
	
	print_r($users); die; 
	
	foreach($users['items'] as $user)
	{
		$users = R::dispense('data');
		
		foreach($user as $key => $value)
		{
			if(is_array($value)) continue; 
	        	$users->$key = $value;
		}
		
		R::store($users);
	}
	
}

?>
