<?php
/**
 * @package	
 * @version	1.0
 * @author	
 * @copyright	(C) 2017 . All rights reserved.
 * @license	GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */
defined('_JEXEC') or die('Restricted access');
?><?php
class plgHikashopYandex_market extends JPlugin{

	function onHikashopCronTrigger(&$messages){
		if(hikashop_level(1)){
			$pluginsClass = hikashop_get('class.plugins');
			$plugin = $pluginsClass->getByName('hikashop','yandex_market');

			if( empty($plugin->params['enable_auto_update']) && empty($plugin->params['local_path'])){
				return true;
			}

			if(empty($plugin->params['frequency'])){
				$plugin->params['frequency'] = 86400;
			}
			if(!empty($plugin->params['last_cron_update']) && $plugin->params['last_cron_update']+$plugin->params['frequency']>time()){
				return true;
			}

			$plugin->params['last_cron_update']=time();
			$pluginsClass->save($plugin);
			$pluginsClass->loadParams($plugin);
			$xml=$this->generateXML();

			$app = JFactory::getApplication();
			if(!empty($xml)){
				if(!empty($plugin->params['local_path'])){
					$path=$this->_getRelativePath($plugin->params['local_path']);
					jimport('joomla.filesystem.file');
					if(!JFile::write(JPATH_ROOT.DS.$path,$xml)){
						$message = 'Could not write YML file to '.JPATH_ROOT.DS.$path;
					}else{
						$message = 'YML file written to '.JPATH_ROOT.DS.$path;
					}
					$messages[] = $message;
					$app->enqueueMessage($message);
				}

				if( empty($plugin->params['enable_auto_update']) ){
					return true;
				}
			}
		}
	}

	function _getRelativePath($path){
		$relativePath=str_replace(JPATH_ROOT.DS,'',$path);
		return $relativePath;
	}

	function downloadXML(){
                include_once(dirname(__FILE__)."/simpleyml.php");
		if(hikashop_level(1)){
			$xml=$this->generateXML();
			@ob_clean();
			header("Pragma: public");
			header("Expires: 0"); // set expiration time
			header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
			header("Content-Type: application/force-download");
			header("Content-Type: application/octet-stream");
			header("Content-Type: application/download");
			header("Content-Disposition: attachment; filename=yml_export_".time().".xml;");
			header("Content-Transfer-Encoding: binary");
			header('Content-Length: '.strlen($xml));
			echo $xml;
			exit;
		}
	}

	function generateXML(){

		$app = JFactory::getApplication();
		$db = JFactory::getDBO();
		$pluginsClass = hikashop_get('class.plugins');
		$plugin = $pluginsClass->getByName('hikashop','yandex_market');
		if(empty($plugin->params['condition'])){
			$plugin->params['condition'] = "new";
		}

		if(@$plugin->params['increase_perf']){
			$memory = '128M';
			$max_execution = '120';
			if($plugin->params['increase_perf'] == 2){
				$memory = '512M';
				$max_execution = '600';
			}elseif($plugin->params['increase_perf'] == 3){
				$memory = '1024M';
				$max_execution = '6000';
			}elseif($plugin->params['increase_perf'] == 10){
				$memory = '4096M';
				$max_execution = '0';
			}
			ini_set('memory_limit',$memory);
			ini_set('max_execution_timeout',$max_execution);
		}

		$query = 'SELECT * FROM '.hikashop_table('product').' WHERE product_access=\'all\' AND product_published=1 AND product_type=\'main\'';
		if(!empty($plugin->params['in_stock_only'])){
			$query .= ' AND product_quantity!=0';
		}
		$db->setQuery($query);
		$products = $db->loadObjectList();

		if(empty($products)){
			return true;
		}

		$ids = array();
		foreach($products as $key => $row){
			$ids[] = $row->product_id;
			$products[$key]->alias = JFilterOutput::stringURLSafe($row->product_name);
		}
		$queryCategoryId = 'SELECT * FROM '.hikashop_table('product_category').' WHERE product_id IN ('.implode(',',$ids).')';
		$db->setQuery($queryCategoryId);
		$categoriesId = $db->loadObjectList();
		foreach($products as $k => $row){
			foreach($categoriesId as $catId){
				if($row->product_id == $catId->product_id){
					$products[$k]->categories_id[] = $catId->category_id;
				}
			}
		}

		$usedCat=array();
		$catList="";
		foreach($products as $product){
			if(!empty($product->categories_id)){
				foreach($product->categories_id as $catId){
					if(!isset($usedCat[$catId])){
						$usedCat[$catId] = $catId;
						$catList .= $catId.',';
					}
				}
			}
		}
		$catList = substr($catList,0,-1);

		$parentCatId = 'product';
		$categoryClass = hikashop_get('class.category');
		$categoryClass->getMainElement($parentCatId);

		$query = 'SELECT DISTINCT b.* FROM '.hikashop_table('category').' AS a LEFT JOIN '.
					hikashop_table('category').' AS b ON a.category_left >= b.category_left WHERE '.
					'b.category_right >= a.category_right AND a.category_id IN ('.$catList.') AND a.category_published=1 AND a.category_type=\'product\' AND b.category_id!='.$parentCatId.' '.
					'ORDER BY b.category_left';
		$db->setQuery($query);
		$categories = $db->loadObjectList();

		$category_path=array();
		$discard_products_without_valid_categories = array();
		foreach($products as $k => $product){
			if(empty($product->categories_id)){
				$discard_products_without_valid_categories[] = $k;
			}else{
				$path = array();
				$at_least_a_category_valid = false;
				foreach($categories as $category){
					foreach($product->categories_id as $catID){
						if( $catID == $category->category_id){
							$at_least_a_category_valid = true;
							if( !isset($category_path[$catID])){
								$category_path[$catID] = $this->_getCategoryParent($category, $categories, $path, $parentCatId);
							}
						}
					}
				}
				if(!$at_least_a_category_valid){
					$discard_products_without_valid_categories[] = $k;
				}
			}
		}
		if(!empty($discard_products_without_valid_categories)){
			foreach($discard_products_without_valid_categories as $k){
				unset($products[$k]);
			}
		}

		foreach($category_path as $id => $mainCat){
			$path='';
			for($i=count($mainCat);$i>0;$i--){
				$path .= $mainCat[$i-1]->category_name.' > ';
			}
			$category_path[$id]['path'] = substr($path,0,-3);
		}


		$queryImage = 'SELECT * FROM '.hikashop_table('file').' WHERE file_ref_id IN ('.implode(',',$ids).') AND file_type=\'product\' ORDER BY file_ordering ASC, file_id ASC';
		$db->setQuery($queryImage);
		$images = $db->loadObjectList();
		$products[$k]->images = array();
		foreach($products as $k => $row){
			$i=0;
			foreach($images as $image){
				if($row->product_id == $image->file_ref_id){
					$products[$k]->images[$i] = new stdClass();
					foreach(get_object_vars($image) as $key => $name){
						$products[$k]->images[$i]->$key = $name;
					}
				}
				$i++;
			}
		}
		$db->setQuery('SELECT * FROM '.hikashop_table('variant').' WHERE variant_product_id IN ('.implode(',',$ids).')');
		$variants = $db->loadObjectList();
		if(!empty($variants)){
			foreach($products as $k => $product){
				foreach($variants as $variant){
					if($product->product_id == $variant->variant_product_id){
						$products[$k]->has_options = true;
						break;
					}
				}
			}
		}


		$zone_id = hikashop_getZone();
		$currencyClass = hikashop_get('class.currency');
		$config =& hikashop_config();
		$main_currency = (int)$config->get('main_currency',1);
		if(empty($plugin->params['price_displayed'])) $plugin->params['price_displayed'] = 'cheapest';

		if($plugin->params['price_displayed'] == 'cheapest'){
			$currencyClass->getListingPrices($products,$zone_id,$main_currency,'cheapest');
		}
		if($plugin->params['price_displayed'] == 'unit'){
			$currencyClass->getListingPrices($products,$zone_id,$main_currency,'unit');
		}
		if($plugin->params['price_displayed'] == 'average'){
			$currencyClass->getListingPrices($products,$zone_id,$main_currency,'range');
			$tmpPrice = 0;
			$tmpTaxPrice = 0;
			foreach($products as $product){
				if(isset($product->prices[0]->price_value)){
					if(count($product->prices) > 1){
						for($i=0;$i<count($product->prices);$i++){
							if($product->prices[$i]->price_value > $tmpPrice){
								$tmpPrice += $product->prices[$i]->price_value;
								$tmpTaxPrice += @$product->prices[$i]->price_value_with_tax;
							}
						}
						$product->prices[0]->price_value = $tmpPrice/count($product->prices);
						$product->prices[0]->price_value_with_tax = $tmpTaxPrice/count($product->prices);
						for($i=1;$i<count($product->prices);$i++){
							unset($product->prices[$i]);
						}
					}
				}
			}
		}
		if($plugin->params['price_displayed'] == 'expensive'){
			$currencyClass->getListingPrices($products,$zone_id,$main_currency,'range');
			$tmpPrice = 0;
			foreach($products as $product){
				if(isset($product->prices[0]->price_value)){
					if(count($product->prices)>1){
						for($i=0;$i<count($product->prices);$i++){
							if($product->prices[$i]->price_value > $tmpPrice){
								$tmpPrice = $product->prices[$i]->price_value;
								$key = $i;
							}
						}
						$product->prices[0] = $product->prices[$key];
						for($i=1;$i<count($product->prices);$i++){
							unset($product->prices[$i]);
						}
					}
				}
			}
		}

		if(!empty($plugin->params['use_brand'])){
			$parentCatId = 'manufacturer';
			$categoryClass->getMainElement($parentCatId);
			$query = 'SELECT DISTINCT * FROM '.hikashop_table('category').' AS a WHERE a.category_published=1 AND a.category_type=\'manufacturer\' AND a.category_parent_id='.$parentCatId;
			$db->setQuery($query);
			$brands = $db->loadObjectList('category_id');
		}

		$config =& hikashop_config();
		$uploadFolder = ltrim(JPath::clean(html_entity_decode($config->get('uploadfolder'))),DS);
		$uploadFolder = rtrim($uploadFolder,DS).DS;
		$this->uploadFolder_url = str_replace(DS,'/',$uploadFolder);
		$this->uploadFolder = JPATH_ROOT.DS.$uploadFolder;
		$app = JFactory::getApplication();
		$this->thumbnail = $config->get('thumbnail',1);
		$this->thumbnail_x = $config->get('thumbnail_x',100);
		$this->thumbnail_y = $config->get('thumbnail_y',100);
		$this->main_thumbnail_x = $this->thumbnail_x;
		$this->main_thumbnail_y = $this->thumbnail_y;
		$this->main_uploadFolder_url = $this->uploadFolder_url;
		$this->main_uploadFolder = $this->uploadFolder;

		$conf = JFactory::getConfig();
		if(!HIKASHOP_J30){
			$siteName = $conf->getValue('config.sitename');
			$siteDesc = $conf->getValue('config.MetaDesc');
		}else{
			$siteName = $conf->get('sitename');
			$siteDesc = $conf->get('MetaDesc');
		}
		$siteAddress = JURI::base();
		$siteAddress = str_replace('administrator/','',$siteAddress);
                
                
                
                //init yml
		$yml = new SimpleYML('<?xml version="1.0" encoding="utf-8"?><!DOCTYPE yml_catalog SYSTEM "shops.dtd"><yml_catalog></yml_catalog>');
                $yml->addAttribute('date', date("Y-m-d H:i"));
       
                //add shop yml
		$yml_shop = $yml->addChild("shop");
                $yml_shop->addChild("name", "Синтетик Полимер | Инновационные полимерные материалы"); //TODO
                $yml_shop->addChild("company", "Синтетик Полимер | Инновационные полимерные материалы"); //TODO
                $yml_shop->addChild("url", JURI::root());
//                $yml_shop->addChild("platform", "Joomla");
//		$yml_shop->addChild("version", JVERSION);
//                $yml_shop->addChild("email", $jshopConfig->contact_email);
		
		//add currencies yml
		$yml_currencies = $yml_shop->addChild("currencies");
		$currency = $yml_currencies->addChild("currency");
                $currency->addAttribute("id", "RUR"); //TODO
                $currency->addAttribute("rate", "1"); //TODO
			
				
		//add categories yml
		$yml_categories = $yml_shop->addChild("categories");        
                
                foreach($categories as $category){
                    $yml_cat = $yml_categories->addChild("category", $category->category_name);
                    $yml_cat->addAttribute("id", $category->category_id);
                    if ($category->category_parent_id){
                        $yml_cat->addAttribute("parentId", $category->category_parent_id);
                    }
                }

                //add offers yml		
                $yml_offers = $yml_shop->addChild("offers");	
//                $adquery = "";
//                        if(!empty($ie_p['in_stock'])) $adquery .= " AND (prod.product_quantity > '0' OR prod.unlimited = '1')";
//                if (count($filtercategory)) $adquery .= " and cat.category_id in (".implode(",",$filtercategory).")";
//                if (count($filtermanufacturer)) $adquery .= " and man.manufacturer_id in (".implode(",",$filtermanufacturer).")";        
//                $query = "SELECT `id`, `field_name`, `productfields_id`, `type` FROM `#__jshopping_ymlexport` WHERE `productfields_id` != 0";
//                $db->setQuery($query);
//                $list = $db->loadObjectList('id');   
//                $productfields_id = array();
//                if($list) foreach ($list as $key=>$val)
//                    $productfields_id[$val->id] = $val->productfields_id;
//                $a_productfields_id = array_unique($productfields_id);
//                $adv_sel="";
//                foreach ($a_productfields_id as $_productfields_id)
//                    $adv_sel .= "prod.extra_field_". $_productfields_id .", " ;
//                $query = "SELECT ".$adv_sel." prod.product_id, prod.product_ean, prod.product_quantity, prod.product_date_added, prod.product_price, prod.product_old_price, tax.tax_value as tax, prod.`".$lang->get('name')."` as name, prod.`".$lang->get('short_description')."` as short_description,  prod.`".$lang->get('description')."` as description, cat.`".$lang->get('name')."` as cat_name, categ.category_id, man.`".$lang->get('name')."` as man_name, prod.currency_id, ".(JVERSION >='3.0.0' ? 'prod.image' : 'prod.product_full_image')." as full_img, prod.product_weight, COUNT(files.product_id) as files_count
//                          FROM `#__jshopping_products` AS prod
//                          LEFT JOIN `#__jshopping_products_to_categories` AS categ USING (product_id)
//                          LEFT JOIN `#__jshopping_categories` as cat on cat.category_id=categ.category_id
//                          LEFT JOIN `#__jshopping_taxes` AS tax ON tax.tax_id = prod.product_tax_id
//                                          LEFT JOIN `#__jshopping_products_files` AS files ON files.product_id = prod.product_id
//                          LEFT JOIN `#__jshopping_manufacturers` AS man ON man.manufacturer_id = prod.product_manufacturer_id
//                          WHERE prod.product_publish = '1' AND cat.category_publish='1' $adquery
//                          GROUP BY prod.product_id";
//                $db->setQuery($query);
//                $products = $db->loadObjectList();
//                $juri = JURI::getInstance();
//                $liveurlhost = $juri->toString(array("scheme",'host', 'port'));
//                $app = JApplication::getInstance('site');
//                $router = $app->getRouter();
//                        $allExtraFields = JSFactory::getAllProductExtraField();
//                $allExtraFieldsValues = JSFactory::getAllProductExtraFieldValue();
//
//                        $model_products = self::getModel();
//                        $_offer_fields = self::getOfferType($ie_p['offer_type']);
//                        $_offer_fields = $_offer_fields[1];

                foreach($products as $product){
                    $yml_offers_offer = $yml_offers->addChild("offer");
                    $available = ($product->product_quantity>0 || $product->product_quantity == -1) ? "true" : "false";
                    $yml_offers_offer->addAttribute("id", $product->product_id);
                    $yml_offers_offer->addAttribute("available", $available);

                    //TODO
//                    if(!empty($ie_p['offer_type']))
//                            $yml_offers_offer->addAttribute("type", $ie_p['offer_type']);
//                    if(!empty($ie_p['bid']))
//                            $yml_offers_offer->addAttribute("bid", $ie_p['bid']);
//                    if(!empty($ie_p['cbid']))
//                            $yml_offers_offer->addAttribute("cbid", $ie_p['cbid']);


//                    $elements = array();
//                    $params = array();	
//                    //var_dump($row,$productfields_id);
//                    foreach ($productfields_id as $key => $_productfields_id){
//                        $extra_field = "extra_field_". $_productfields_id;
//                        if ($allExtraFields[$_productfields_id]->type == 1){
//                            $extraFieldValue = $row->$extra_field;
//                        } elseif($allExtraFields[$_productfields_id]->type == 0 and $allExtraFields[$_productfields_id]->multilist == 0){
//                            $extraFieldExplodeValue = explode(',', $row->$extra_field);
//                            $extraFieldValue = $allExtraFieldsValues[reset($extraFieldExplodeValue)];
//                        }
//                        if($extraFieldValue){					
//                                                if($list[$key]->type == 2){
//                                                        $field_name = str_replace('[^a-zA-z_]', '', $list[$key]->field_name);
//                                                        $true_false_array_element = array('manufacturer_warranty','seller_warranty');
//                                                        if(in_array($field_name,$true_false_array_element)){
//                                                                switch ($extraFieldValue){
//                                                                        case 'no':
//                                                                        case 0: $extraFieldValue = 'false'; break;
//                                                                        case 'yes':
//                                                                        case 1: $extraFieldValue = 'true'; break;
//                                                                }
//                                                        }
//                                                        $elements[$field_name] = $extraFieldValue;						
//                                                }else{
//                                                        $params[$list[$key]->field_name] = $extraFieldValue;
//                                                }					
//                                        }
//                    }
//
//
//                                if($ie_p['oldprice'] and !in_array('oldprice',$_offer_fields))
//                                        $_offer_fields[] = 'oldprice';
//                                if($ie_p['rec'] and !in_array('rec',$_offer_fields))
//                                        $_offer_fields[] = 'rec';
//                                if($ie_p['weight'] and !isset($params['Weight']))
//                                        $params['Weight'] = '';
//
//                                foreach($_offer_fields as $_ofkey){
//                                        if(isset($elements[$_ofkey])){
//                                                $yml_offers_offer->addChild($_ofkey, $elements[$_ofkey]);
//                                                if($_ofkey == 'age')
//                                                        $param->addAttribute('unit', 'year');
//                                                continue;
//                                        }
                                        
                                        if(!empty($product->product_canonical)){   
                                            $yml_offers_offer->addChild("url", "http://holax/".$product->product_canonical); //TODO
                                        }
                                        
                                        if(isset($product->prices[0]->price_value)){
                                            $price_name = 'price_value';
                                            if(!empty($plugin->params['taxed_price'])){
                                                    $price_name = 'price_value_with_tax';
                                            }
                                            if(empty($product->product_min_per_order)){
                                                    $price = round($product->prices[0]->$price_name, 2);
                                            }
                                            else{
                                                    $price = round($product->prices[0]->$price_name, 2)*$product->product_min_per_order;
                                            }   
                                            $price = round($price,2);
                                            $yml_offers_offer->addChild("price", $price);
                                        }
                                        
                                        if(1) $yml_offers_offer->addChild("currencyId", "RUR"); //TODO
                                        
                                        $yml_offers_offer->addChild("categoryId", $product->categories_id[0]);
                                        
                                        if(isset($product->images) && count($product->images)){
                                            $i = 0;
                                            $name = "image_link";
                                            foreach($product->images as $image){
//                                                    if($i < 10){
//                                                             $xml .= "\t".'<g:'.$name.'>'.htmlspecialchars($siteAddress.$this->main_uploadFolder_url.$image->file_path).'</g:'.$name.'>'."\n";
//                                                             $name = "additional_image_link";
//                                                             $i++;
//                                                    }
                                                    $yml_offers_offer->addChild("picture",htmlspecialchars($siteAddress.$this->main_uploadFolder_url.$image->file_path));
                                                    break;
                                            }
                                        }
                                        
                                        $yml_offers_offer->addChild("name", $product->product_name);
                                        
                                        //$desc = $ie_p['delete_html_tags'] ? strip_tags($product->short_description) : $product->short_description;
                                        if(!empty($product->product_description)){   
                                            $yml_offers_offer->addChild("description",$product->product_description);
                                        }
                                        
                                        $yml_offers_offer->addChild("sales_notes", "100"); //TODO
                                        

//                                        //START
//                                        switch($_ofkey){
//                                                case 'url':
//                                                        $url0 = "index.php?option=com_jshopping&controller=product&task=view&category_id=".$row->category_id."&product_id=".$row->product_id."&Itemid=".$shop_item_id;
//                                                        $uri = $router->build($url0);
//                                                        $url = $uri->toString();
//                                                        $url = str_replace('/administrator', '', $url);
//                                                        $url = $liveurlhost.$url;
//                                                        $yml_offers_offer->addChild("url", $url);
//                                                        break;
//                                                case 'price':
//                                                        $price = $row->product_price;
//                                                        $value = $all_currency[$row->currency_id]->currency_value;
//                                                        if (!$value) $value = 1;
//                                                        $price = $price / $value;
//                                                        $price = $price * $all_currency[$ie_p['currency']]->currency_value;
//                                                        $price = round($price,2);
//                                                        $yml_offers_offer->addChild("price", $price);
//                                                        break;
//                                                case 'oldprice':						
//                                                        $price = $row->product_old_price;
//                                                        if($price <= 0) break;
//                                                        $value = $all_currency[$row->currency_id]->currency_value;
//                                                        if (!$value) $value = 1;
//                                                        $price = $price / $value;
//                                                        $price = $price * $all_currency[$ie_p['currency']]->currency_value;
//                                                        $price = round($price,2);
//                                                        $yml_offers_offer->addChild("oldprice", $price);
//                                                        break;
//                                                case 'currencyId':
//                                                        $currencyId = $all_currency[$ie_p['currency']]->currency_code_iso;
//                                                        if($currencyId)
//                                                                $yml_offers_offer->addChild("currencyId", $currencyId);
//                                                        break;					
//                                                case 'categoryId':
//                                                        $yml_offers_offer->addChild("categoryId", $row->category_id);
//                                                        break;
//                                                case 'picture':
//                                                        if(!empty($row->full_img))
//                                                                $yml_offers_offer->addChild("picture",(JVERSION >='3.0.0' ? getPatchProductImage($row->full_img, 'full',1): $jshopConfig->image_product_live_path."/".$row->full_img));
//                                                        break;			
//                                                case 'store':
//                                                        $yml_offers_offer->addChild("store", $ie_p['store']);
//                                                        break;
//                                                case 'pickup':
//                                                        $yml_offers_offer->addChild("pickup", $ie_p['pickup']);
//                                                        break;
//                                                case 'delivery':
//                                                        $yml_offers_offer->addChild("delivery", $ie_p['delivery']);
//                                                        break;
//                                                case 'local_delivery_cost':
//                                                        if($ie_p['local_delivery_cost'])
//                                                                $yml_offers_offer->addChild("local_delivery_cost", $ie_p['local_delivery_cost']);
//                                                        break;
//                                                case 'title':
//                                                case 'model':
//                                                case 'name':
//                                                        $yml_offers_offer->addChild($_ofkey, $row->name);
//                                                        break;
//                                                case 'vendor':
//                                                case 'publisher':
//                                                        if($row->man_name)
//                                                                $yml_offers_offer->addChild($_ofkey, $row->man_name);
//                                                        break;
//                                                case 'description':
//                                                        $desc = $ie_p['delete_html_tags'] ? strip_tags($row->short_description) : $row->short_description;
//                                                        if(!empty($desc))
//                                                                $yml_offers_offer->addChild("description",$desc);
//                                                        break;
//                                                case 'sales_notes':
//                                                        if($ie_p['sales_notes'])
//                                                                $yml_offers_offer->addChild("sales_notes", mb_substr($ie_p['sales_notes'],50));
//                                                        break;
//                                                case 'adult':
//                                                        if(!empty($ie_p['adult']))
//                                                                $yml_offers_offer->addChild("adult", 'true');
//                                                        break;					
//                                                case 'ISBN':
//                                                case 'barcode':
//                                                        if (empty($ie_p['barcode']) && $row->product_ean)
//                                                                $yml_offers_offer->addChild($_ofkey, $row->product_ean);
//                                                        break;
//                                                case 'cpa':
//                                                        $yml_offers_offer->addChild("cpa", (!empty($ie_p['cpa']) ? '1' : '0'));
//                                                        break;
//                                                case 'downloadable':
//                                                        if($row->files_count > 0)
//                                                                $yml_offers_offer->addChild("downloadable", 'true');
//                                                        break;
//                                                case 'rec':
//                                                        if(!empty($ie_p['rec'])){
//                                                                $_ps = $model_products->getRelatedProducts($row->product_id);
//                                                                $_pid = array();
//                                                                foreach ($_ps as $_p)
//                                                                        $_pid[] = $_p->product_id;
//                                                                if(count($_pid) > 0)
//                                                                        $yml_offers_offer->addChild("rec", implode(',',$_pid));
//                                                        }
//                                                        break;
//                                                case 'param':
//                                                        foreach($params as $kp => $vp){							
//                                                                if($kp == 'Weight'){
//                                                                        $value = round($row->product_weight,2);
//                                                                        if($value > 0){
//                                                                                $param = $yml_offers_offer->addChild('param', $value);					
//                                                                                $param->addAttribute('name', $kp);
//                                                                                $param->addAttribute('unit', sprintUnitWeight());
//                                                                                //$param->addAttribute('value', $value);
//                                                                        }
//                                                                }else{
//                                                                        $param = $yml_offers_offer->addChild('param', $vp);					
//                                                                        $param->addAttribute('name', $kp);
//                                                                }
//                                                        }
//                                                        break;
//                                        }//END switch
//                                        
                                }
                
                
/*                
		$xml = '<?xml version="1.0" encoding="UTF-8" ?>'."\n".
					'<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">'."\n".
					"\t".'<channel>'."\n".
								"\t\t".'<title><![CDATA[ '.$siteName.' ]]></title>'."\n".
								"\t\t".'<description><![CDATA[ '.$siteDesc.' ]]></description>'."\n".
								"\t\t".'<link><![CDATA[ '.$siteAddress.' ]]></link>'."\n"."\n";   */
//		$productClass = hikashop_get('class.product');
//		foreach($products as $product) {
//			if(isset($product->prices[0]->price_value)){
//				$price_name = 'price_value';
//				if(!empty($plugin->params['taxed_price'])){
//					$price_name = 'price_value_with_tax';
//				}
//				if(empty($product->product_min_per_order)){
//					$price = round($product->prices[0]->$price_name, 2);
//				}
//				else{
//					$price = round($product->prices[0]->$price_name, 2)*$product->product_min_per_order;
//				}
//				$currencies = array();
//				$currencyClass = hikashop_get('class.currency');
//				$ids[$product->prices[0]->price_currency_id] = $product->prices[0]->price_currency_id;
//				$currencies = $currencyClass->getCurrencies($ids[$product->prices[0]->price_currency_id],$currencies);
//				$currency = reset($currencies);
//				$xml .= '<item>'."\n";
//				$productClass->addAlias($product);
//				if($product->product_weight_unit == 'mg'){
//					$product->product_weight = $product->product_weight*1000;
//					$product->product_weight_unit = 'g';
//				}
//				$xml .= "\t".'<g:id>'.$product->product_id.'</g:id>'."\n";
//				$xml .= "\t".'<title><![CDATA[ '.$product->product_name.' ]]></title>'."\n";
//				$itemID = '';
//
//				if(!empty($plugin->params['item_id'])){
//					$itemID = '&Itemid='.$plugin->params['item_id'];
//				}
//				if(!empty($product->product_canonical)){
//					$xml .= "\t".'<g:link><![CDATA[ '.str_replace('/administrator/','/',hikashop_cleanURL($product->product_canonical)).' ]]></g:link>'."\n";
//				}else{
//					$xml .= "\t".'<g:link><![CDATA[ '.$siteAddress.'index.php?option=com_hikashop&ctrl=product&task=show&cid='.$product->product_id.'&name='.$product->alias.$itemID.' ]]></g:link>'."\n";
//				}
//				$xml .= "\t".'<g:price>'.$price.' '.$currency->currency_code.'</g:price>'."\n";
//				if(!empty($product->product_description)){
//					if(@$plugin->params['preview']){
//						 $xml .= "\t".'<g:description><![CDATA[ '.strip_tags(preg_replace('#<hr *id="system-readmore" */>.*#is','',$product->product_description)).' ]]></g:description>'."\n";
//					}else{
//						$xml .= "\t".'<g:description><![CDATA[ '.strip_tags($product->product_description).' ]]></g:description>'."\n";
//					}
//				}elseif(!empty($plugin->params['message'])){
//					$xml .= "\t".'<g:description><![CDATA[ '.$plugin->params['message'].' ]]></g:description>'."\n";
//				}else{
//					$xml .= "\t".'<g:description>No description</g:description>'."\n";
//				}
//				$xml .= $this->_additionalParameter($product,$plugin,'condition','condition');
//
//				$xml .= $this->_additionalParameter($product,$plugin,'gender','gender');
//
//				$xml .= $this->_additionalParameter($product,$plugin,'gtin','gtin');
//
//				$xml .= $this->_additionalParameter($product,$plugin,'age_group','age_group');
//
//				$xml .= $this->_additionalParameter($product,$plugin,'size','size');
//
//				$xml .= $this->_additionalParameter($product,$plugin,'color','color');
//
//				$xml .= $this->_additionalParameter($product,$plugin,'identifier_exists','identifier_exists');
//
//				$xml .= $this->_addShipping($product,$plugin);
//
//				if(!empty($plugin->params['use_brand']) && !empty($brands[$product->product_manufacturer_id]->category_name)){
//					$xml .= "\t".'<g:brand><![CDATA[ '.$brands[$product->product_manufacturer_id]->category_name.' ]]></g:brand>'."\n";
//				}else{
//					$xml .= $this->_additionalParameter($product,$plugin,'brand','brand');
//				}
//
//				$xml .= $this->_additionalParameter($product,$plugin,'category','google_product_category');
//
//				if($plugin->params['add_code']){
//					$xml .= "\t".'<g:mpn><![CDATA[ '.str_replace(array(' ','-'),array('',''),$product->product_code).' ]]></g:mpn>'."\n";
//				}
//
//				if(isset($product->images) && count($product->images)){
//					$i = 0;
//					$name = "image_link";
//					foreach($product->images as $image){
//						if($i < 10){
//							 $xml .= "\t".'<g:'.$name.'>'.htmlspecialchars($siteAddress.$this->main_uploadFolder_url.$image->file_path).'</g:'.$name.'>'."\n";
//							 $name = "additional_image_link";
//							 $i++;
//						}
//					}
//				}
//
//				$type='';
//				foreach($product->categories_id as $catID){
//					foreach($category_path as $id=>$catPath){
//						if($id == $catID){
//							if(strlen($type.'"'.$catPath['path'].'",') > 750) continue;
//							$type .= '"'.$catPath['path'].'",';
//						}
//					}
//				}
//				if(!empty($type)){
//					$type = substr($type,0,-1);
//					$xml .= "\t".'<g:product_type><![CDATA[ '.$type.' ]]></g:product_type>'."\n";
//				}
//
//
//				if($product->product_quantity != -1){
//					$xml .= "\t".'<g:quantity>'.$product->product_quantity.'</g:quantity>'."\n";
//				}
//				if($product->product_quantity == 0){
//					$xml .= "\t".'<g:availability>out of stock</g:availability>'."\n";
//				}
//				else{
//					$xml .= "\t".'<g:availability>in stock</g:availability>'."\n";
//				}
//				$xml .= "\t".'<g:shipping_weight>'.(float)hikashop_toFloat($product->product_weight).' '.$product->product_weight_unit.'</g:shipping_weight>'."\n";
//				$xml .= '</item>'."\n";
//			}
//		}
//		$xml .= '</channel>'."\n".'</rss>'."\n";
                $filename = "yml_feed_".time().".xml";
		$yml->saveAsXML($filename);
	}

	function _addShipping(&$product,&$plugin){
		$xml = '';

		if(empty($plugin->params['shipping'])){
			return $xml;
		}

		$column = $plugin->params['shipping'];
		if(isset($product->$column)){
			if(empty($product->$column)) return $xml;

			$text = $product->$column;
		}else{
			$text = $column;
		}

		$shipping_methods = explode(',',$text);

		foreach($shipping_methods as $shipping_method){
			$shipping_data = explode(':',$shipping_method);
			if(count($shipping_data)!=4) continue;
			$xml.="\t".'<g:shipping>'."\n";
			$xml.="\t\t".'<g:country>'.$shipping_data[0].'</g:country>'."\n";
			if(!empty($shipping_data[1])) $xml.="\t\t".'<g:region>'.$shipping_data[1].'</g:region>'."\n";
			if(!empty($shipping_data[2])) $xml.="\t\t".'<g:service>'.$shipping_data[2].'</g:service>'."\n";
			$xml.="\t\t".'<g:price>'.$shipping_data[3].'</g:price>'."\n";
			$xml.="\t".'</g:shipping>'."\n";
		}
		return $xml;
	}

	function _additionalParameter(&$product,&$plugin,$param,$attribute){
		$xml = '';
		if(!empty($plugin->params[$param])){
			$column = $plugin->params[$param];
			if(isset($product->$column)){
				if(empty($product->$column)) return $xml;

				$text = $product->$column;
			}else{
				$text = $column;
			}
			$xml="\t".'<g:'.$attribute.'><![CDATA[ '.$text.' ]]></g:'.$attribute.'>'."\n";
		}
		return $xml;
	}

	
	function _getCategoryParent($theCat, &$categories, $path, $parentCatId){
		if($theCat->category_parent_id==$parentCatId){
			$path[]=$theCat;
			return $path;
		}
		foreach($categories as $category){
			if($category->category_id==$theCat->category_parent_id){
				$path[]=$theCat;
				$path=$this->_getCategoryParent($category,$categories,$path, $parentCatId);
			}
		}
		return $path;
	}

}
