import { __ } from '@wordpress/i18n'
import { useContext, useRef } from 'react';
import SbUtils from '../../../../Utils/SbUtils';
import Button from "../../../Common/Button";
import SettingsScreenContext from '../../../Context/SettingsScreenContext';

const ManageImportFeed = ( props ) => {

    const {
        sbCustomizer,
        editorNotification,
        editorTopLoader
    } = useContext( SettingsScreenContext) ;

    const importFeedRef = useRef();

    const notificationsContent = {
        success : {
            icon : 'success',
            text : __( 'Feed Imported', 'sb-customizer' )
        }
    };

    const importFeed = ( event ) => {
        const feedFile = event.target.files[0];
        if ( feedFile ) {
            const formData = {
                action : 'sbr_import_feed_settings',
                feedFile : feedFile
            }
            SbUtils.ajaxPost(
                sbCustomizer.ajaxHandler,
                formData,
                ( data ) => { //Call Back Function
                },
                editorTopLoader,
                editorNotification,
                notificationsContent
            )
        }
    }


    return (
        <>
        <div className='sb-settings-input-ctn'>
            <input
                className='sb-import-input'
                type='file'
                accept='.json,application/json'
                ref={ importFeedRef }
                onChange={ ( event ) => {
                    importFeed( event )
                }}
            />
            <Button
                text={  __( 'Import', 'sb-customizer' ) }
                type='secondary'
                size='medium'
                icon='import'
                iconSize='11'
                onClick={ () => {
                    if ( importFeedRef.current ) {
                        importFeedRef.current.click();
                    }
                } }
            />
        </div>
        </>
    )
}

export default ManageImportFeed;