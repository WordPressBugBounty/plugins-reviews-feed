import { __ } from '@wordpress/i18n'
import { useContext, useRef } from 'react';
import SbUtils from '../../../../Utils/SbUtils';
import Button from "../../../Common/Button";
import SettingsScreenContext from '../../../Context/SettingsScreenContext';

const ManageImportCollection = ( props ) => {

    const {
        sbCustomizer,
        editorNotification,
        editorTopLoader
    } = useContext( SettingsScreenContext) ;

    const importCollectionRef = useRef();

    let notificationsContent = {
        success : {}
    },
	successNotification = {
    	type : 'success',
        icon : 'success',
        text : __( 'Collection Imported!', 'sb-customizer' ),
        time : 4000
    },
    invalidNotification = {
        type : 'error',
        icon : 'notice',
        text : __( 'Invalid JSON file!', 'sb-customizer' ),
        time : 5000
    }

    const importCollection = ( event ) => {
        const feedFile = event.target.files[0];
        if ( feedFile ) {
            const formData = {
                action : 'sbr_import_full_collection',
                feedFile : feedFile
            }
            SbUtils.ajaxPost(
                sbCustomizer.ajaxHandler,
                formData,
                ( data ) => { //Call Back Function
					if (data.success === false) {
						console.log(data)
						notificationsContent.success = invalidNotification;
					} else {
						notificationsContent.success = successNotification;
					}
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
                ref={ importCollectionRef }
                onChange={ ( event ) => {
                    importCollection( event )
                }}
            />
            <Button
                text={  __( 'Import', 'sb-customizer' ) }
                type='secondary'
                size='medium'
                icon='import'
                iconSize='11'
                onClick={ () => {
                    if ( importCollectionRef.current ) {
                        importCollectionRef.current.click();
                    }
                } }
            />
        </div>
        </>
    )
}

export default ManageImportCollection;