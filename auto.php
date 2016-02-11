<?php
session_start();
mysql_connect("localhost","root","lodos2005");
mysql_select_db("ledix");
mysql_query("SET NAMES 'utf8'");
mysql_query("SET CHARACTER SET utf8");
mysql_query("SET COLLATION_CONNECTION = 'utf8_turkish_ci'");
error_reporting(E_ALL);
ini_set('display_errors', 'On');
date_default_timezone_set('Europe/Istanbul');

function durum($mesaj){
	mysql_query("update durum set durum='".$mesaj."' where id='1'");
}

function derece($a){
	$a = explode("Temp=",$a);
	$a = explode("*",$a[1]);
	return $a[0];
}

function nem($a){
	$a = explode("Humidity=",$a);
	return $a[1];
}

function role_kontrol($role_index,$konum="1"){
	$a="";
	//pinList = [2, 3, 4, 17, 27, 22, 10, 9]
	//pinList = nem(1),ısıtıcı(2),klima(3),fan cihazi(4),salmalamba(5),analamba(6),masalamba(7),alarm(8)
	if ($konum=="1")
		$konum="GPIO.LOW";
	if ($konum=="0")
		$konum="GPIO.HIGH";
	
	if ($role_index==1)
		$a= "GPIO.output(2,$konum)";
	if ($role_index==2)
		$a= "GPIO.output(3,$konum)";
	if ($role_index==3)
		$a= "GPIO.output(4,$konum)";
	if ($role_index==4)
		$a= "GPIO.output(17,$konum)";
	if ($role_index==5)
		$a= "GPIO.output(27,$konum)";
	if ($role_index==6)
		$a= "GPIO.output(22,$konum)";
	if ($role_index==7)
		$a= "GPIO.output(10,$konum)";
	if ($role_index==8)
		$a= "GPIO.output(9,$konum)";
	
	
	
	return $a."\n";
}


function led_yak($kafes_no,$kacinci_led,$color){
	
	if ($kafes_no<=75){  //ilk 75 kafes 20li ledlerden oluşuyor.
		$index = (($kacinci_led)+($kafes_no*20)-20);  
	}else{
		$index = (75*20) + (($kacinci_led)+(($kafes_no - 75)*10)-10); 
	}
	
	
	
	$a = "tek_led(strip,Color(".$color."),".($index-1).")\n";
	
	return $a;
}

function gun_donumu_saati_bul(){
	$yil_basi = date_create(date("Y").'-01-01');
	$bugun    = date_create(date("Y").'-'.date("m").'-'.date("d"));
	$gun_farki = $yil_basi->diff($bugun)->format('%a');

	if ($gun_farki<76){
		$saat_baslangic= "12";
		$dakika_baslangic= "30";
		$dakika_soyle = $gun_farki * 2;
	}else if($gun_farki<256){
		$saat_baslangic= "15";
		$dakika_baslangic= "00";
		$dakika_soyle = (((($gun_farki-76) * 2)+2) * -1);
	}else if($gun_farki<=365){
		$saat_baslangic= "09";
		$dakika_baslangic= "00";
		$dakika_soyle = (($gun_farki-256) * 2) +2;
	}
	
	
	$dakika_hesapla = ($saat_baslangic*60)+$dakika_baslangic+$dakika_soyle;
	

	return $dakika_hesapla;
	// return 60;
	
}


$ayar = mysql_fetch_assoc(mysql_query("select * from kumes_ayarlar"));


$gun_donumu_saat_=  gun_donumu_saati_bul();
$saat = date("G"); //0-23  sıfır dolgusuz
$dakika = date("i"); //00-59  sıfır dolgulu
$suan=time();
$parlaklik=120;
$gun_dogum_saat=$ayar["gun_dogum_saat"];
$gun_dogum_dakika=$ayar["gun_dogum_dakika"];
$toplam_led_sayisi = 107;

function led_sayisi($kacinci_kafes){
	if ($kacinci_kafes<=75){
		return 20; //ilk 75 te 20 led var;
	}else{
		return 10; //sonrakilerde 10 led var;
	}
}


/********************************                              RÖLE KONTROLU                     *********************************/
$sicak = exec ("sudo python /var/www/html/sicaklik_script.py",$retvals);	
$sicaklik=derece($sicak);
$nem=str_replace("%","",nem($sicak));
echo "Sıcalık: ".$sicaklik."*C   - NEM: %".$nem."<br />";
$script2="#!/usr/bin/python
import RPi.GPIO as GPIO
import time

GPIO.setmode(GPIO.BCM)

# init list with pin numbers

pinList = [2, 3, 4, 17, 27, 22, 10, 9]

# loop through pins and set mode and state to 'low'

for i in pinList:
    GPIO.setup(i, GPIO.OUT)
    GPIO.output(i, GPIO.HIGH)

# time to sleep between operations in the main loop


# main loop

";


if ($nem>=$ayar["nem_alici_max"]){
	//eğer nem yüzde 50 ve büyükse
	//nem alıcıyı çalıştır
	$script2.=role_kontrol(1,1);
	echo "röle durum 3. eğer nem yüzde ".$ayar["nem_alici_max"]." ve büyükse nem alıcıyı çalıştır<br />";
}

if ($nem<=$ayar["nem_alici_min"]){
	//eğer nem yüzde 48 ve küçükse
	//nem alıcıyı kapat
	$script2.=role_kontrol(1,0);
	echo "röle durum 4. eğer nem yüzde ".$ayar["nem_alici_min"]." ve küçükse nem alıcıyı kapat<br />";
}

if ($dakika<=$ayar["fan_cihazi_dk"]){
	//her saat ilk $ayar["fan_cihazi_dk"] dakika fan cihazını çalıştır.
	$script2.=role_kontrol(4,1);
	echo "röle durum 5. her saat ilk ".$ayar["fan_cihazi_dk"]." dakika fan cihazını çalıştır.<br />";
}else{
	//her saat son 40 dakika fan cihazını durdur.
	$script2.=role_kontrol(4,0);
	echo "röle durum 6. her saat son ".(60-$ayar["fan_cihazi_dk"])." dakika fan cihazını durdur.<br />";
}
	
if ($sicaklik<=$ayar["isi_kontrol_min"]){
	//eğer sıcaklık 14 derece ve düşükse
	//fanı kapat, ısıtıcıyı çalıştır
	$script2.=role_kontrol(2,1);
	$script2.=role_kontrol(4,0);
	echo "röle durum 1. eğer sıcaklık ".$ayar["isi_kontrol_min"]." derece ve düşükse ısı kontrolcüyü çalıştır - fanı kapat<br />";
}

if ($sicaklik>=$ayar["isi_kontrol_max"]){
	//eğer sıcaklık 26 derece ve büyükse
	//ısı kontrolcüyü kapat
	$script2.=role_kontrol(2,0);
	echo "röle durum 2. eğer sıcaklık ".$ayar["isi_kontrol_max"]." derece ve büyükse ısı kontrolcüyü kapat<br />";
	
	
	
}

$sicaklik=round($sicaklik);


$rolee=array();
$roleler = mysql_query("select * from roleler");
while ($r = mysql_fetch_assoc($roleler)){
	if ($r["role_durum"]=="ON"){
		$script2.=role_kontrol($r["role_num"],1);
	}else if ($r["role_durum"]=="OFF"){
		$script2.=role_kontrol($r["role_num"],0);
	}
	$rolee[$r["role_num"]]=$r["sure"];
	
}




/********************************                              RÖLE KONTROLU                     *********************************/


$script="
";

echo "Gün Işığı Saati: ".floor($gun_donumu_saat_/60)." Saat".($gun_donumu_saat_%60)." Dakika";

echo "<hr />";



$gun_dogum = mktime ($gun_dogum_saat , $gun_dogum_dakika , "0");

$gun_isigi_suresi =$gun_donumu_saat_; //120dk
$gun_isigi_suresi_ = $gun_dogum + $gun_isigi_suresi*60;


$fark = $suan-$gun_dogum;

echo "SAAT:".$saat.":".$dakika;
echo "<br />gun_dogum:".$gun_dogum;
echo "<br />şuanda:".$suan;
echo "<br />fark:".$fark;
echo "<br />";

$mysql_kafes_bilgiler = mysql_query("select * from kafes_isiklari");
$kafesler=array();
while ($r = mysql_fetch_assoc($mysql_kafes_bilgiler)){
	$kafesler[$r["kafes_no"]]=$r["kafes_max_yuzde"];
}


// BAKICI ÇİFTLER İÇİN IŞIKLARI KIS
$mysql_yumurta_bilgiler = mysql_query("select * from yumurta where kafes_no<>''");
echo "Kuluçkadakiler<br />";
while ($r = mysql_fetch_assoc($mysql_yumurta_bilgiler)){
	
	echo $r["kafes_no"];
	
	
	if ($r["kafes_no"]>=16 && $r["kafes_no"]<=35)
		$r["kafes_no"]=35-($r["kafes_no"]-16); //ters bağlantı
	if ($r["kafes_no"]>=56 && $r["kafes_no"]<=75)
		$r["kafes_no"]=75-($r["kafes_no"]-56); //ters bağlantı
	if ($r["kafes_no"]>=76 && $r["kafes_no"]<=83)
		$r["kafes_no"]=83-($r["kafes_no"]-76); //ters bağlantı
	if ($r["kafes_no"]>=92 && $r["kafes_no"]<=99)
		$r["kafes_no"]=99-($r["kafes_no"]-92); //ters bağlantı
	
	echo "(".$r["kafes_no"].") ->";
	
	$kafesler[$r["kafes_no"]]="60";
	
}


if ($fark<0){
	for ($i=1;$i<=$toplam_led_sayisi;$i++){//107 kafes
	
		for ($l=1;$l<=led_sayisi($i);$l++){//20 LED
			$script.=led_yak($i,$l,"0,0,0");
			
			if ($l==led_sayisi($i)){// ay ışığı
				$script.=led_yak($i,$l,"00,30,35");
				
			}
		}
	}
	
	//gün batımında ışıklarıda kapat (eğer 5dakika kadar önce manuel olarak açılmış ise dokunma)
		if ($suan-$rolee[5]>(60)){
			$script2.=role_kontrol(5,0);
		}
		if ($suan-$rolee[6]>(60)){
			$script2.=role_kontrol(6,0);
		}
	
		if ($suan-$rolee[7]>(60)){
			$script2.=role_kontrol(7,0);
		}
}elseif ($fark>0 && $fark<360){
	echo "<br />1. adım.   GünDoğumu +00<06 Dakika -> 20.Led Sunrise<br />";
	durum("Gün Doğuyor -> 1.Adım");
	for ($i=1;$i<=$toplam_led_sayisi;$i++){//107 kafes
		for ($l=1;$l<=led_sayisi($i);$l++){//20 LED
			if ($l==led_sayisi($i)){//ilk led
				$script.=led_yak($i,$l,"182,126,91");
				
				$parlaklik = floor($fark / 10);

					
					
			}else{
				$script.=led_yak($i,$l,"0,0,0");
			}
		}
	}
	
	
}elseif ($fark>360 && $fark<720){
	echo "<br />2. adım.   GünDoğumu +06<12 Dakika -> 20. ve 15. Led Sunrise<br />";
	durum("Gün Doğuyor -> 2.Adım");
	$parlaklik = 30;
	for ($i=1;$i<=$toplam_led_sayisi;$i++){//107 kafes
		for ($l=1;$l<=led_sayisi($i);$l++){//20 LED
			if ($l==led_sayisi($i) or $l==(led_sayisi($i) - (led_sayisi($i)/4))){//20. ve 15. led
				$script.=led_yak($i,$l,"182,126,91");
			}else{
				$script.=led_yak($i,$l,"0,0,0");
			}
		}
	}
	
}elseif ($fark>720 &&$fark<1080){
	echo "<br />3. adım.   GünDoğumu +12<28 Dakika -> 20 15 ve 10. Led Sunrise<br />";
	durum("Gün Doğuyor -> 3.Adım");
	$parlaklik = 30;
	for ($i=1;$i<=$toplam_led_sayisi;$i++){//107 kafes
		for ($l=1;$l<=led_sayisi($i);$l++){//20 LED
			if ($l==led_sayisi($i) or $l==(led_sayisi($i) - (led_sayisi($i)/4)) or $l==(led_sayisi($i) - (led_sayisi($i)/2))){//20.  15. ve 10. led
				$script.=led_yak($i,$l,"182,126,91");
			}else{
				$script.=led_yak($i,$l,"0,0,0");
			}
		}
	}
	
}elseif ($fark>1080 && $fark<1440){
	echo "<br />4. adım.   GünDoğumu +18<24 Dakika -> 20 15 10 ve 5. Led Sunrise<br />";
	durum("Gün Doğuyor -> 4.Adım");
	$parlaklik = 30;
	for ($i=1;$i<=$toplam_led_sayisi;$i++){//107 kafes
		for ($l=1;$l<=led_sayisi($i);$l++){//20 LED
			if ($l==led_sayisi($i) or $l==(led_sayisi($i) - (led_sayisi($i)/4)) or $l==(led_sayisi($i) - (led_sayisi($i)/2)) or $l==(led_sayisi($i)/4)){//20.  15. 10. ve 5. led
				$script.=led_yak($i,$l,"182,126,91");
			}else{
				$script.=led_yak($i,$l,"0,0,0");
			}
		}
	}
	
}elseif ($fark>1440 && $fark<1800){
	echo "<br />5. adım.   GünDoğumu +24<30 Dakika -> 20 15 10 5 ve 1. Led Sunrise<br />";
	durum("Gün Doğuyor -> 5.Adım");
	$parlaklik = 30;
	for ($i=1;$i<=$toplam_led_sayisi;$i++){//107 kafes
		for ($l=1;$l<=led_sayisi($i);$l++){//20 LED
		
			if ($l==1 or $l==led_sayisi($i) or $l==(led_sayisi($i) - (led_sayisi($i)/4)) or $l==(led_sayisi($i) - (led_sayisi($i)/2)) or $l==(led_sayisi($i)/4)){//1. 5. 10. 15. ve 20. led
				$script.=led_yak($i,$l,"182,126,91");
			}else{
				$script.=led_yak($i,$l,"0,0,0");
			}
		}
	}
	
}elseif ($fark>1800 && $fark<3600){
	echo "<br />6. adım.   GünDoğumu <30 Dakika -> 1-20. Led 	(daylight % 10 - %100)<br />";
	durum("Gün Doğuyor -> 6.Adım");
	$yarim_saat_farki = $fark - 1800;
	
	
	$fark_yuzde = round(($yarim_saat_farki/1800)*100);
	$isik_yuzde =$isik_yuzde_esas = round((200/100)*$fark_yuzde)+55 ;
	
	
	echo "<br />".$isik_yuzde."<br />";
	echo "<br />%".$fark_yuzde."<br />";
	for ($i=1;$i<=$toplam_led_sayisi;$i++){//107 kafes
		if (($kafesler[$i]*2.55)<$isik_yuzde_esas){
			$isik_yuzde=round($kafesler[$i]*2.55);
			if ($isik_yuzde<25) $isik_yuzde=25;
		}
		else
			$isik_yuzde=$isik_yuzde_esas;
		
		if ($kafesler[$i]=="0"){
			$isik_yuzde="0";
		}
		for ($l=1;$l<=led_sayisi($i);$l++){//20 LED
				$script.=led_yak($i,$l,"$isik_yuzde,$isik_yuzde,$isik_yuzde");
		}
	}

}elseif ($fark>=3600){
	echo "<br />7. adım.   GünDoğumu <60 Dakika -> 255 FULL LEDs<br />";
		
	$batim_fark = (($gun_isigi_suresi_ )- $suan);
	
	durum("Gün Doğdu -> Gün Batımına kalan süre ".gmdate("H:i:s", $batim_fark)." (".date("H:i",($gun_isigi_suresi_+3600)).")");
	$isik_yuzde = $isik_yuzde_esas = 255;
	for ($i=1;$i<=$toplam_led_sayisi;$i++){//107 kafes
	
		if (($kafesler[$i]*2.55)<$isik_yuzde_esas){
			$isik_yuzde=round($kafesler[$i]*2.55);
			if ($isik_yuzde<22) $isik_yuzde=22;
		}else 
			$isik_yuzde=$isik_yuzde_esas;
		
		if ($kafesler[$i]=="0"){
			$isik_yuzde="0";
		}
		
		for ($l=1;$l<=led_sayisi($i);$l++){//20 LED
				
				$script.=led_yak($i,$l,"$isik_yuzde,$isik_yuzde,$isik_yuzde");
		}
	}
	
	
}		 
	
	
	
$batim_fark = (($gun_isigi_suresi_ )- $suan);
	
echo "<hr />".$gun_isigi_suresi_."-".date("d.m.y H:i",$gun_isigi_suresi_);
echo "<br />kalan zaman:".$batim_fark."<br />";
	
	
if ($batim_fark<0){ //gün batıyorsa
	$batim_fark=($batim_fark)+3600;
	
	echo "<br />batim_fark:".$batim_fark."<br />";
	
	
	
	if ($batim_fark>1800 && $batim_fark<3600){
		
		echo "<br />1. adım.   Ledler Yavaş Yavaş Sönüyor<br />";
		echo "Sonraki adıma ".($batim_fark-1800)." saniye Kaldı";
		
		durum("Gün Batıyor -> ".($batim_fark-1800)." Saniye sonra sunset durumuna geçilecek.");
		
		
		$yarim_saat_farki = $batim_fark - 1800;
		
		
		$fark_yuzde = (($yarim_saat_farki/1800)*100);
		$isik_yuzde = $isik_yuzde_esas = round((200/100)*$fark_yuzde)+55 ;
		
		
		echo "<br /><br />%".$fark_yuzde."<br />";
		echo "".$isik_yuzde."<br />";
		
		for ($i=1;$i<=$toplam_led_sayisi;$i++){//107 kafes
			if (($kafesler[$i]*2.55)<$isik_yuzde_esas){
				$isik_yuzde=round($kafesler[$i]*2.55);
				if ($isik_yuzde<35) $isik_yuzde=35;
				//echo $i." kafes ".$isik_yuzde."<br />";
			}else
				$isik_yuzde=$isik_yuzde_esas;
			
			if ($kafesler[$i]=="0"){
				$isik_yuzde="0";
			}
		
			for ($l=1;$l<=led_sayisi($i);$l++){//20 LED
					$script.=led_yak($i,$l,"$isik_yuzde,$isik_yuzde,$isik_yuzde");
			}
		}
		
		
		
		

	}
	if ($batim_fark>1440 && $batim_fark<=1800){
		echo "<br />2. adım.    -> 1 5 10 15 ve 20. Led Sunrise<br />";
		echo "Sonraki adıma ".($batim_fark-1440)." saniye Kaldı";
		durum("Gün Batıyor -> 1. Adım");
		
		$parlaklik = 30;
		for ($i=1;$i<=$toplam_led_sayisi;$i++){//107 kafes
			for ($l=1;$l<=led_sayisi($i);$l++){//20 LED
				if ($l==1 or $l==led_sayisi($i) or $l==(led_sayisi($i) - (led_sayisi($i)/4)) or $l==(led_sayisi($i) - (led_sayisi($i)/2)) or $l==(led_sayisi($i)/4)){//1. 5. 10. 15. ve 20. led
					$script.=led_yak($i,$l,"182,126,91");
				}else{
					$script.=led_yak($i,$l,"0,0,0");
				}
			}
		}
		
		
	}elseif ($batim_fark>1080 && $batim_fark<=1440){
		echo "<br />3. adım.    -> 20 15 10 ve 5. Led Sunrise<br />";
		echo "Sonraki adıma ".($batim_fark-1080)." saniye Kaldı";
		durum("Gün Batıyor -> 2. Adım");
		
		$parlaklik = 30;
		for ($i=1;$i<=$toplam_led_sayisi;$i++){//107 kafes
			for ($l=1;$l<=led_sayisi($i);$l++){//20 LED
				if ($l==led_sayisi($i) or $l==(led_sayisi($i) - (led_sayisi($i)/4)) or $l==(led_sayisi($i) - (led_sayisi($i)/2)) or $l==(led_sayisi($i)/4)){//20. 15. 10. 5. led
					$script.=led_yak($i,$l,"182,126,91");
				}else{
					$script.=led_yak($i,$l,"0,0,0");
				}
			}
		}
		
	}elseif ($batim_fark>720 && $batim_fark<=1080){
		echo "<br />4. adım.    -> 1 5 ve 10. Led Sunrise<br />";
		echo "Sonraki adıma ".($batim_fark-720)." saniye Kaldı";
		durum("Gün Batıyor -> 3. Adım");
		$parlaklik = 30;
		for ($i=1;$i<=75;$i++){//75 kafes
			for ($l=1;$l<=led_sayisi($i);$l++){//20 LED
				if ($l==led_sayisi($i) or $l==(led_sayisi($i) - (led_sayisi($i)/4)) or $l==(led_sayisi($i) - (led_sayisi($i)/2))){//2. 15. 10. led
					$script.=led_yak($i,$l,"182,126,91");
				}else{
					$script.=led_yak($i,$l,"0,0,0");
				}
			}
		}
		
	}elseif ($batim_fark>360 && $batim_fark<=720){
		echo "<br />5. adım.    -> 1. ve 5. Led Sunrise<br />";
		echo "Sonraki adıma ".($batim_fark-360)." saniye Kaldı";
		durum("Gün Batıyor -> 4. Adım");
		$parlaklik = 30;
		for ($i=1;$i<=$toplam_led_sayisi;$i++){//107 kafes
			for ($l=1;$l<=led_sayisi($i);$l++){//20 LED
				if ($l==led_sayisi($i) or $l==(led_sayisi($i) - (led_sayisi($i)/4))){//20. 15. led
					$script.=led_yak($i,$l,"182,126,91");
				}else{
					$script.=led_yak($i,$l,"0,0,0");
				}
			}
		}
		
	}elseif ($batim_fark>0 && $batim_fark<=360){
		echo "<br />6. adım.   -> 1. Led Sunrise<br />";
		echo "Sonraki adıma ".($batim_fark)." saniye Kaldı";
		// durum("Gün Batıyor -> 5. Adım");
		$parlaklik = 30;
		for ($i=1;$i<=$toplam_led_sayisi;$i++){//107 kafes
			for ($l=1;$l<=led_sayisi($i);$l++){//20 LED
				if ($l==led_sayisi($i)){//20. led
					$script.=led_yak($i,$l,"182,126,91");
					$parlaklik = floor($batim_fark / 10);
				}else{
					$script.=led_yak($i,$l,"0,0,0");
				}
			}
		}
		
		durum("Gün Batıyor -> 5. Adım (".$parlaklik.")");
	}elseif ($batim_fark<0){
		echo "<br />7. adım.   -> tüm ledler kapalı<br />";
		
		durum("Gün Battı");
		for ($i=1;$i<=$toplam_led_sayisi;$i++){//107 kafes
			for ($l=1;$l<=led_sayisi($i);$l++){//20 LED
				$script.=led_yak($i,$l,"0,0,0");
				if ($l==led_sayisi($i)){// ay ışığı
					$script.=led_yak($i,$l,"00,30,35");
					
				}
			}
		}

	}
	
	//gün batımında ışıklarıda kapat (eğer 5dakika kadar önce manuel olarak açılmış ise dokunma)
		if ($suan-$rolee[5]>(60)){
			$script2.=role_kontrol(5,0);
		}
		if ($suan-$rolee[6]>(60)){
			$script2.=role_kontrol(6,0);
		}
	
		if ($suan-$rolee[7]>(60)){
			$script2.=role_kontrol(7,0);
		}
		
		
		
}			


	
	
	
	
	
	$script.="strip.show()";

	// echo $parlaklik;
$script="import time
from neopixel import *

LED_COUNT      = 1820      # Number of LED pixels.

def tek_led(strip,color,led_index,wait_ms=1):
	strip.setPixelColor(led_index,color)


		
if __name__ == '__main__':
	strip = Adafruit_NeoPixel(LED_COUNT, 18, 800000, 5, False, ".$parlaklik.")
	strip.begin()

".$script;	 
	
	
	
	
	
$myfile = fopen("/var/www/html/isik_oto_script.py", "w") or die("Unable to open file!");
fwrite($myfile, $script);
fclose($myfile);
exec ("sudo python /var/www/html/isik_oto_script.py");	


$myfile = fopen("/var/www/html/role_script.py", "w") or die("Unable to open file!");
fwrite($myfile, $script2);
fclose($myfile);
exec ("sudo python /var/www/html/role_script.py");	




?>
