<?php

class SimpleYML extends SimpleXMLElement{
    
    public function addChild($name, $val = null, $namespace = null){
        if (isset($val)){
            return parent::addChild($name, $this->_filter_value($val));
        }else{
            return parent::addChild($name);
        }
    }
    
    protected function _filter_value($val){
        $val = str_replace("&", "&amp;", $val);
        return $val;
    }
    
    public function asXML($filename = null){
        $str = parent::asXML();
        $str = str_replace("'", "&apos;", $str);
    
        return $str;
    }
    
    public function saveAsXML($file){
        $f = fopen($file, "w");
        fwrite($f, "\xEF\xBB\xBF"); //save as utf8 with BOM
        fwrite($f,$this->asXML());
        fclose($f);
    }
}