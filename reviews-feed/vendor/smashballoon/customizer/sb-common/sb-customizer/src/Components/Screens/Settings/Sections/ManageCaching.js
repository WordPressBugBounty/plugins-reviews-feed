import { __ } from "@wordpress/i18n";
import { useContext, useState } from "react";
import SbUtils from "../../../../Utils/SbUtils";
import Button from "../../../Common/Button";
import SettingsScreenContext from "../../../Context/SettingsScreenContext";

const ManageCaching = (  ) => {
     const {
        sbSettings,
        editorTopLoader,
        editorNotification
    } = useContext( SettingsScreenContext) ;

    const [ cacheLoading, setCacheLoading ] = useState( false );

    const clearAllCaches = () => {
        setCacheLoading(true);
        const formData = {
            action : 'sbr_clear_all_caches'
        },
        notificationsContent = {
            success : {
                icon : 'success',
                text : __('Cleared all Caches', 'sb-customizer')
            }
        };

        SbUtils.ajaxPost(
            sbSettings.ajaxHandler,
            formData,
            ( data ) => { //Call Back Function
                setCacheLoading( 'success' )
                setTimeout(() => {
                    setCacheLoading( false )
                }, 2000);
            },
            editorTopLoader,
            editorNotification,
            notificationsContent
        )
    }

    return (
        <div className='sb-setting-clearaches-ctn sb-fs'>
            <span>{ sbSettings?.pluginStatus?.license_tier === 3  ?  __( 'New Reviews collected twice daily.', 'sb-customizer' ) : __( 'New Reviews collected daily.', 'sb-customizer' ) }</span>
            <Button
                type='secondary'
                size='medium'
                icon={cacheLoading === 'success' ? 'success' : 'reset'}
                loading={cacheLoading === true}
                disabled={ cacheLoading !== false }
                text={ __( 'Clear All Caches', 'sb-customizer' ) }
                onClick={ () => {
                    clearAllCaches()
                } }
            />
        </div>

    )
}
export default ManageCaching;