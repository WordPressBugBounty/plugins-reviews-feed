import { useContext } from "react";
import SbUtils from "../../../Utils/SbUtils";
import Button from "../../Common/Button";
import Select from "../../Common/Select";
import Switcher from "../../Common/Switcher";
import SettingsScreenContext from "../../Context/SettingsScreenContext";
import ManageApiKeys from "./Sections/ManageApiKeys";
import ManageLicenseKey from "./Sections/ManageLicenseKey";
import ManageSource from "./Sections/ManageSource";
import ManageImportFeed from "./Sections/ManageImportFeed";
import ManageExportFeed from "./Sections/ManageExportFeed";
import ManageTranslation from "./Sections/ManageTranslation";
import ManageCaching from "./Sections/ManageCaching";
import ManageLicenseKeyFree from "./Sections/ManageLicenseKeyFree";
import ManageImportCollection from "./Sections/ManageImportCollection";
import ManageExportCollection from "./Sections/ManageExportCollection";

const SettingSectionOutput = ( props ) => {

    const {
        settingsPage,
        sbSettings,
        editorTopLoader,
        editorNotification
    } = useContext( SettingsScreenContext );

    props = props.section;
    const changeSingleSetting = ( el, isValue = false, stringify = false ) => {
        let settingValue = null;
        switch ( props.type ) {
            case 'switcher':
                settingValue = settingsPage?.pluginSettings[props.id] === props.options.enabled
                            ? props.options.disabled : props.options.enabled;
            break;
            default:
                settingValue = isValue === true ? el : el.currentTarget.value;
            break;
        }
        settingsPage?.setPluginSettings(  {
            ...settingsPage?.pluginSettings,
            [ props.id ] : stringify === true ? JSON.stringify(settingValue) : settingValue
        }  );
    }

    const ajaxButtonSettings = ( btn ) => {
        const formData = {
            action : btn.action,
            data : btn.data || null
        },
        notificationsContent = btn.notification || null;
        SbUtils.ajaxPost(
            sbSettings.ajaxHandler,
            formData,
            ( data ) => { //Call Back Function

            },
            editorTopLoader,
            editorNotification,
            notificationsContent
        )
    }

    const sectionOutput = () => {
        let output = null;
        switch ( props.type ) {
            case 'caching':
                output =  <ManageCaching/>;
                break;
            case 'sources':
                output =  <ManageSource/>;
                break;
            case 'apikeys':
                output = <ManageApiKeys/>;
                break;
            case 'licensekeyfree':
                output =  <ManageLicenseKeyFree/>;
                break;
            case 'licensekey':
                output = <ManageLicenseKey/>;
                break;
            case 'importfeed':
                output = <ManageImportFeed/>;
                break;
            case 'exportfeed':
                output = <ManageExportFeed/>;
                break;
            case 'importcollection':
                output = <ManageImportCollection/>;
                break;
            case 'exportcollection':
                output = <ManageExportCollection/>;
                break;
            case 'translation':
                output = <ManageTranslation
                    translations={ props }
                    value={ settingsPage?.pluginSettings[props.id] }
                    onChangeTranslation={ ( value ) => {
                        changeSingleSetting( value, true )
                    } }
                />;
                break;

            case 'select':
                const optKeys = Object.keys( props.options );
                output =
                    <Select
                        customClass='sb-select-control'
                        type={ props.inputType }
                        placeholder={ props.placeholder }
                        cols={ props.cols }
                        rows={ props.rows }
                        maxLength={ props.maxLength }
                        minLength={ props.minLength }
                        leadingIcon={ props.inputLeadingIcon }
                        leadingText={ props.inputLeadingText }
                        trailingIcon={ props.inputTrailingIcon }
                        trailingText={ props.inputTrailingText }
                        size='small'
                        value={ settingsPage?.pluginSettings[props.id] }
                        onChange={ ( event ) => {
                            changeSingleSetting( event )
                        } }
                    >
                        {
                            optKeys.map( ( optKey, optInd ) => {
                                return (
                                    <option key={ optKey } value={ optKey }>{ props.options[optKey] }</option>
                                )
                            })
                        }
                    </Select>;
            break;
            case 'switcher':
                output =
                    <Switcher
                        customClass='sb-switcher-control'
                        value={ settingsPage?.pluginSettings[props.id] }
                        enabled={ props.options.enabled }
                        disabled={ props.options.disabled }
                        label={ props?.label }
                        labelDescription={ props?.labelDescription }
                        labelStrong={ props?.labelStrong }
                        size='medium'
                        onChange={ ( event ) => {
                            changeSingleSetting( event )
                        } }
                    />
                ;
                break;

            default:
                output = null;
            break;
        }
        return output;
    }


    const ajaxButtonOutput = () => {
        let buttonOutput = null;

        if( props?.ajaxButton !== undefined ){
            buttonOutput = <Button
                size='small'
                boxshadow='false'
                type={props?.ajaxButton?.type || 'secondary' }
                text={props?.ajaxButton?.text || 'secondary' }
                icon={props?.ajaxButton?.icon}
                onClick={ () => {
                    ajaxButtonSettings( props?.ajaxButton )
                } }
            />
       }
       return buttonOutput;
    }

    return (
        <>
            <div className="sb-settings-section-insider sb-fs">
                { sectionOutput() }
                { ajaxButtonOutput() }
            </div>
        </>
    )

}
export default SettingSectionOutput;